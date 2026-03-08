<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\AnalyzerService;

class AnalyzerServiceTest extends AbstractServiceTestCase
{
    private AnalyzerService $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new AnalyzerService($this->connection, $this->prefix);
    }

    // --- analyzeShops ---

    public function testAnalyzeShopsReturnsExpectedStructure(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 1, 'name' => 'Default', 'active' => 1],
            ['id_shop' => 2, 'name' => 'Shop 2', 'active' => 1],
        ]);

        $result = $this->analyzer->analyzeShops();

        $this->assertArrayHasKey('shops', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('inactive', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testAnalyzeShopsCountsCorrectly(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 1, 'name' => 'Default', 'active' => 1],
            ['id_shop' => 2, 'name' => 'Inactive', 'active' => 0],
            ['id_shop' => 3, 'name' => 'Shop 3', 'active' => 1],
        ]);

        $result = $this->analyzer->analyzeShops();

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['active']);
        $this->assertSame(1, $result['inactive']);
    }

    public function testAnalyzeShopsStatusOkWhenAllActive(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 1, 'name' => 'Default', 'active' => 1],
        ]);

        $result = $this->analyzer->analyzeShops();

        $this->assertSame('ok', $result['status']);
    }

    public function testAnalyzeShopsStatusWarningWhenInactiveExists(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 1, 'name' => 'Default', 'active' => 1],
            ['id_shop' => 2, 'name' => 'Disabled', 'active' => 0],
        ]);

        $result = $this->analyzer->analyzeShops();

        $this->assertSame('warning', $result['status']);
    }

    // --- analyzePaymentModules ---

    public function testAnalyzePaymentModulesDetectsInactiveModule(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id_module' => 1, 'name' => 'paypal', 'active' => 0,
                'shops_count' => 0, 'carriers_count' => 0,
            ],
        ]);
        $this->connection->method('fetchOne')->willReturn(2); // totalShops

        $result = $this->analyzer->analyzePaymentModules();

        $this->assertTrue($result['has_errors']);
        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['modules'][0]['issues']);
    }

    public function testAnalyzePaymentModulesDetectsMissingShopAssociation(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id_module' => 1, 'name' => 'stripe_official', 'active' => 1,
                'shops_count' => 1, 'carriers_count' => 5,
            ],
        ]);
        $this->connection->method('fetchOne')->willReturn(3); // totalShops = 3, only 1 associated

        $result = $this->analyzer->analyzePaymentModules();

        $this->assertTrue($result['has_warnings']);
        $this->assertSame('warning', $result['status']);
    }

    public function testAnalyzePaymentModulesOkWhenFullyCovered(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id_module' => 1, 'name' => 'paypal', 'active' => 1,
                'shops_count' => 2, 'carriers_count' => 5,
            ],
        ]);
        $this->connection->method('fetchOne')->willReturn(2);

        $result = $this->analyzer->analyzePaymentModules();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['modules_ok']);
    }

    // --- analyzeCarriers ---

    public function testAnalyzeCarriersDetectsMissingShopAssociation(): void
    {
        $this->mockFetchOneSequence([2]); // totalShops
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id_carrier' => 1, 'id_reference' => 1, 'name' => 'Colissimo',
                'active' => 1, 'deleted' => 0, 'is_module' => 1,
                'external_module_name' => 'colissimo',
                'zones_count' => 3, 'delivery_count' => 10, 'shops_count' => 1,
            ],
        ]);

        $result = $this->analyzer->analyzeCarriers();

        $this->assertTrue($result['has_errors']);
        $this->assertNotEmpty($result['carriers'][0]['issues']);
    }

    public function testAnalyzeCarriersIgnoresModuleCarriersWithoutDelivery(): void
    {
        $this->mockFetchOneSequence([2]); // totalShops
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id_carrier' => 1, 'id_reference' => 1, 'name' => 'Colissimo',
                'active' => 1, 'deleted' => 0, 'is_module' => 1,
                'external_module_name' => 'colissimo',
                'zones_count' => 3, 'delivery_count' => 0, 'shops_count' => 2,
            ],
        ]);

        $result = $this->analyzer->analyzeCarriers();

        // Module carriers without delivery entries are OK (they use their own logic)
        $this->assertSame(1, $result['carriers_ok']);
    }

    // --- analyzeStocks ---

    public function testAnalyzeStocksDetectsEmptyStocks(): void
    {
        $this->connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['share_stock' => 0, 'name' => 'Default'], // getShareStockConfig
            ['count' => 0, 'total_quantity' => null],   // shop1
            ['count' => 0, 'total_quantity' => null],   // global
            ['count' => 0, 'total_quantity' => null],   // total
        );
        $this->connection->method('fetchAllAssociative')->willReturn([]); // shop_distribution
        $this->mockFetchOneSequence([0]); // productsWithoutStock

        $result = $this->analyzer->analyzeStocks();

        $this->assertSame('error', $result['status']);
    }

    public function testAnalyzeStocksOkWithShareStockAndGlobalEntries(): void
    {
        $this->connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['share_stock' => 1, 'name' => 'Default'], // getShareStockConfig
            ['count' => 0, 'total_quantity' => null],   // shop1 (empty = ok for share_stock)
            ['count' => 500, 'total_quantity' => 1000],  // global
            ['count' => 500, 'total_quantity' => 1000],  // total
        );
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->buildShopDistribution(0, 500, 1000),
        ]);
        $this->mockFetchOneSequence([0]); // productsWithoutStock

        $result = $this->analyzer->analyzeStocks();

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['share_stock']);
    }

    public function testAnalyzeStocksWarnsShareStockWithShop1Entries(): void
    {
        $this->connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['share_stock' => 1, 'name' => 'Default'], // share_stock enabled
            ['count' => 500, 'total_quantity' => 1000],  // shop1 still has entries
            ['count' => 0, 'total_quantity' => null],    // global is empty
            ['count' => 500, 'total_quantity' => 1000],  // total
        );
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->buildShopDistribution(1, 500, 1000),
        ]);
        $this->mockFetchOneSequence([0]); // productsWithoutStock

        $result = $this->analyzer->analyzeStocks();

        $this->assertSame('warning', $result['status']);
    }

    // --- analyzeProducts ---

    public function testAnalyzeProductsReturnsStepsArray(): void
    {
        // totalShops, sourceShopId, totalLangs, totalProducts
        // then a series of queries for each step
        $this->mockFetchOneSequence([
            2,   // totalShops
            1,   // sourceShopId
            2,   // totalLangs
            100, // totalProducts
            50,  // productLangSource
            0,   // productLangMissing
            0,   // productShopMissing
            80,  // totalCombinations
            0,   // combinationsMissing
            0,   // defaultOnMissing
            20,  // totalAttributes
            0,   // attributeShopMissing
            10,  // totalCategories
            10,  // categoryLangSource
            0,   // categoryLangMissing
            0,   // categoryShopMissing
            50,  // totalSpecificPrices
            50,  // globalSpecificPrices
            0,   // shopSpecificPrices (no shop-specific = skip missing check)
            5,   // totalFeatures
            0,   // featureShopMissing
            0,   // priceZeroInShop
            0,   // specificPriceZero
            0,   // specificPrice100Off
        ]);

        $result = $this->analyzer->analyzeProducts();

        $this->assertArrayHasKey('steps', $result);
        $this->assertArrayHasKey('total_products', $result);
        $this->assertArrayHasKey('total_missing', $result);
        $this->assertArrayHasKey('status', $result);

        // Should have 9 steps
        $this->assertCount(9, $result['steps']);
    }

    public function testAnalyzeProductsOkWhenNothingMissing(): void
    {
        $this->mockFetchOneSequence([
            2, 1, 2, 100,
            50, 0,     // product_lang: 0 missing
            0,         // product_shop: 0 missing
            80, 0, 0,  // combinations: 0 missing, 0 defaultOnMissing
            20, 0,     // attributes: 0 missing
            10, 10, 0, // category_lang: 0 missing
            0,         // category_shop: 0 missing
            50, 50, 0, // specific_price: all global, 0 shop-specific
            5, 0,      // feature_shop: 0 missing
            0, 0, 0,   // price anomaly: priceZero, spZero, sp100Off
        ]);

        $result = $this->analyzer->analyzeProducts();

        $this->assertSame(0, $result['total_missing']);
        $this->assertSame('ok', $result['status']);
    }

    public function testAnalyzeProductsErrorWhenProductShopMissing(): void
    {
        $this->mockFetchOneSequence([
            2, 1, 2, 100,
            50, 0,       // product_lang
            25,          // product_shop: 25 missing = error
            80, 0, 0,    // combinations, defaultOnMissing
            20, 0,       // attributes
            10, 10, 0,   // category_lang
            0,           // category_shop
            50, 50, 0,   // specific_price
            5, 0,        // feature_shop
            0, 0, 0,     // price anomaly
        ]);

        $result = $this->analyzer->analyzeProducts();

        $this->assertSame('error', $result['status']);
        $this->assertSame(25, $result['total_missing']);
    }

    public function testAnalyzeProductsErrorWhenDefaultOnMissing(): void
    {
        $this->mockFetchOneSequence([
            2, 1, 2, 100,
            50, 0,       // product_lang
            0,           // product_shop: 0 missing
            80, 0, 15,   // combinations: 0 missing entries, 15 defaultOnMissing
            20, 0,       // attributes
            10, 10, 0,   // category_lang
            0,           // category_shop
            50, 50, 0,   // specific_price
            5, 0,        // feature_shop
            0, 0, 0,     // price anomaly
        ]);

        $result = $this->analyzer->analyzeProducts();

        $this->assertSame('error', $result['status']);
        $this->assertSame(15, $result['total_missing']);
        // Step 3 should show the combined count
        $this->assertSame(15, $result['steps'][2]['missing']);
        $this->assertSame('error', $result['steps'][2]['status']);
    }

    public function testAnalyzeProductsStepTablesAreCorrect(): void
    {
        $this->mockFetchOneSequence([
            2, 1, 2, 100,
            50, 0, 0, 80, 0, 0, 20, 0, 10, 10, 0, 0, 50, 50, 0, 5, 0, 0, 0, 0,
        ]);

        $result = $this->analyzer->analyzeProducts();

        $tables = array_column($result['steps'], 'table');
        $expected = [
            'product_lang', 'product_shop', 'product_attribute_shop',
            'attribute_shop', 'category_lang', 'category_shop',
            'specific_price', 'feature_shop', 'product_shop',
        ];
        $this->assertSame($expected, $tables);
    }
}
