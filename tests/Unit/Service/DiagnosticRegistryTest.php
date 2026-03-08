<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ScVerifyMultishop\Service\AnalyzerService;
use ScVerifyMultishop\Service\DiagnosticRegistry;
use ScVerifyMultishop\Service\DiagnosticsService;

class DiagnosticRegistryTest extends TestCase
{
    private AnalyzerService&MockObject $analyzer;
    private DiagnosticsService&MockObject $diagnostics;
    private DiagnosticRegistry $registry;

    protected function setUp(): void
    {
        $this->analyzer = $this->createMock(AnalyzerService::class);
        $this->diagnostics = $this->createMock(DiagnosticsService::class);

        $this->registry = new DiagnosticRegistry(
            $this->analyzer,
            $this->diagnostics
        );
    }

    public function testRunAllDiagnosticsReturnsExpectedStructure(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertIsInt($result['timestamp']);
    }

    public function testRunAllDiagnosticsReturnsAllDiagnosticTypes(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $expectedKeys = [
            'shop_group', 'shops', 'payments', 'carriers', 'products',
            'image_shop', 'stocks', 'module_config', 'shop_override',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result['items'], "Missing diagnostic: {$key}");
        }
    }

    public function testRunAllDiagnosticsDoesNotContainExcludedTypes(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertArrayNotHasKey('giftcards', $result['items']);
        $this->assertArrayNotHasKey('sizecharts', $result['items']);
        $this->assertArrayNotHasKey('brand', $result['items']);
        $this->assertArrayNotHasKey('paypal_installment', $result['items']);
    }

    public function testSummaryCountsMatchItems(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $summary = $result['summary'];
        // shop_override is excluded from summary (custom merged card)
        $this->assertSame(count($result['items']) - 1, $summary['total']);
        $this->assertSame(
            $summary['total'],
            $summary['ok'] + $summary['warning'] + $summary['error']
        );
    }

    public function testAllOkWhenNoIssues(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertSame(0, $result['summary']['error']);
        $this->assertSame(0, $result['summary']['warning']);
        $this->assertSame($result['summary']['total'], $result['summary']['ok']);
    }

    public function testEachItemHasRequiredFields(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $requiredFields = ['type', 'label', 'status', 'message', 'issue_count', 'fix_type', 'needs_fix', 'details'];

        foreach ($result['items'] as $key => $item) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $item, "Item '{$key}' missing field '{$field}'");
            }
        }
    }

    public function testNeedsFixIsFalseWhenStatusIsOk(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        foreach ($result['items'] as $key => $item) {
            if ($item['status'] === 'ok') {
                $this->assertFalse($item['needs_fix'], "Item '{$key}' is OK but needs_fix is true");
            }
        }
    }

    public function testProductsWithMissingDataShowsWarning(): void
    {
        $this->analyzer->method('analyzeProducts')->willReturn([
            'total_products' => 100, 'total_missing' => 50,
            'steps' => [
                ['table' => 'product_shop', 'label' => 'Assoc', 'missing' => 50, 'status' => 'warning'],
            ],
            'status' => 'warning',
        ]);
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertSame('warning', $result['items']['products']['status']);
        $this->assertSame(50, $result['items']['products']['issue_count']);
        $this->assertTrue($result['items']['products']['needs_fix']);
    }

    public function testStocksWithNullQuantitiesShowsError(): void
    {
        $this->analyzer->method('analyzeStocks')->willReturn([
            'count_global' => 500, 'count_shop1' => 0, 'count_total' => 500,
            'shop_distribution' => [
                ['id_shop' => 0, 'null_quantities' => 15],
            ],
            'status' => 'ok',
        ]);
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertSame('error', $result['items']['stocks']['status']);
        $this->assertGreaterThan(0, $result['items']['stocks']['issue_count']);
        $this->assertSame('null_quantities', $result['items']['stocks']['fix_type']);
    }

    public function testTimestampIsRecent(): void
    {
        $this->mockAllServicesOk();

        $before = time();
        $result = $this->registry->runAllDiagnostics();
        $after = time();

        $this->assertGreaterThanOrEqual($before, $result['timestamp']);
        $this->assertLessThanOrEqual($after, $result['timestamp']);
    }

    public function testShopOverrideWithIssuesShowsWarning(): void
    {
        $this->diagnostics->method('checkShopOverrideConfigurations')->willReturn([
            'status' => 'warning',
            'modules_with_issues' => [['name' => 'paypal', 'issue_count' => 7]],
            'total_issues' => 7,
        ]);
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        $this->assertSame('warning', $result['items']['shop_override']['status']);
        $this->assertSame(7, $result['items']['shop_override']['issue_count']);
        $this->assertTrue($result['items']['shop_override']['needs_fix']);
        $this->assertSame('shop_override', $result['items']['shop_override']['fix_type']);
    }

    public function testShopOverrideIsExcludedFromSummaryCount(): void
    {
        $this->mockAllServicesOk();

        $result = $this->registry->runAllDiagnostics();

        // 'shop_override' must not appear in the summary count
        $countedItems = array_filter(
            $result['items'],
            fn (array $item): bool => !in_array($item['type'], ['shop_override'], true)
        );
        $this->assertSame(count($countedItems), $result['summary']['total']);
    }

    private function mockAllServicesOk(): void
    {
        $this->diagnostics->method('checkShopGroupSharing')->willReturn([
            'all_shares_enabled' => true, 'status' => 'ok', 'issues' => [],
        ]);
        $this->analyzer->method('analyzeShops')->willReturn(['status' => 'ok', 'total' => 2]);
        $this->analyzer->method('analyzePaymentModules')->willReturn([
            'modules' => [['name' => 'paypal']], 'modules_ok' => 1,
            'has_errors' => false, 'has_warnings' => false,
        ]);
        $this->analyzer->method('analyzeCarriers')->willReturn([
            'active' => 3, 'carriers_ok' => 3, 'status' => 'ok',
        ]);
        $this->analyzer->method('analyzeProducts')->willReturn([
            'total_products' => 100, 'total_missing' => 0, 'steps' => [], 'status' => 'ok',
        ]);
        $this->diagnostics->method('checkImageShop')->willReturn([
            'missing_associations' => 0, 'status' => 'ok',
        ]);
        $this->analyzer->method('analyzeStocks')->willReturn([
            'count_global' => 500, 'count_shop1' => 0, 'count_total' => 500,
            'shop_distribution' => [['id_shop' => 0, 'null_quantities' => 0]],
            'status' => 'ok',
        ]);
        $this->diagnostics->method('checkModuleConfigurations')->willReturn([
            'status' => 'ok', 'modules_with_issues' => [], 'total_issues' => 0,
        ]);
        $this->diagnostics->method('checkShopOverrideConfigurations')->willReturn([
            'status' => 'ok', 'modules_with_issues' => [], 'total_issues' => 0,
        ]);
    }
}
