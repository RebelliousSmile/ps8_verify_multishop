<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\ConfigFixer;

class ConfigFixerTest extends AbstractServiceTestCase
{
    private ConfigFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new ConfigFixer($this->connection, 'low977');
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(['module_config', 'shop_override'], $this->fixer->getSupportedTypes());
    }

    public function testPaypalInstallmentIsNotSupported(): void
    {
        $this->assertNotContains('paypal_installment', $this->fixer->getSupportedTypes());
    }

    // ── Preview without module ──────────────────────────────────────────

    public function testPreviewWithoutModuleSingleModuleAutoSelects(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'mylogin', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertTrue($result['success']);
        $this->assertSame('module_config', $result['type']);
        $this->assertSame('colissimo', $result['selected_module']);
        $this->assertSame(1, $result['changes']['keys_affected']);
        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertCount(1, $result['steps']);
    }

    public function testPreviewWithoutModuleNoIssues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'mylogin', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => 'mylogin', 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['modules']);
        $this->assertSame(0, $result['changes']['total_issues']);
    }

    public function testPreviewWithoutModuleMultipleModules(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'login', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
                ['id_configuration' => 20, 'name' => 'STRIPE_OFFICIAL_MODE', 'value' => '1', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'stripe_official'],
                ['id_configuration' => 21, 'name' => 'STRIPE_OFFICIAL_MODE', 'value' => '', 'id_shop' => 1, 'id_shop_group' => 0, 'module_name' => 'stripe_official'],
                ['id_configuration' => 22, 'name' => 'STRIPE_OFFICIAL_MODE', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'stripe_official'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertCount(2, $result['modules']);
        $this->assertSame(3, $result['changes']['total_issues']);
    }

    // ── Preview with module ─────────────────────────────────────────────

    public function testPreviewWithModuleReturnsDetailedPreview(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'mylogin', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'colissimo']);

        $this->assertTrue($result['success']);
        $this->assertSame('colissimo', $result['selected_module']);
        $this->assertSame(1, $result['changes']['keys_affected']);
        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame('configuration', $result['steps'][0]['table']);
        $this->assertSame(1, $result['steps'][0]['missing']);
    }

    public function testPreviewWithModuleMultipleKeys(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'login', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 1, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
                ['id_configuration' => 12, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
                ['id_configuration' => 20, 'name' => 'COLISSIMO_MODE', 'value' => 'live', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 21, 'name' => 'COLISSIMO_MODE', 'value' => '', 'id_shop' => 1, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'colissimo']);

        $this->assertSame(2, $result['changes']['keys_affected']);
        $this->assertSame(3, $result['changes']['total_entries_to_fix']);
        $this->assertCount(2, $result['steps']);
    }

    public function testPreviewWithInvalidModuleNameReturnsError(): void
    {
        $this->mockFetchAllSequence([[]]);

        $result = $this->fixer->preview('module_config', ['module' => 'invalid-module!']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function testPreviewWithModuleNotFoundReturnsError(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'x', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'stripe_official']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    // ── Preview masking ─────────────────────────────────────────────────

    public function testPreviewMasksSensitiveValues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_PASSWORD', 'value' => 'secretpass', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_PASSWORD', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'colissimo']);

        $step = $result['steps'][0];
        $this->assertStringContainsString('sec*******', $step['label']);
        $this->assertStringNotContainsString('secretpass', $step['label']);
    }

    public function testPreviewTruncatesLongValues(): void
    {
        $longValue = str_repeat('a', 100);

        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_DATA', 'value' => $longValue, 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_DATA', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'colissimo']);

        $step = $result['steps'][0];
        $this->assertStringContainsString('...', $step['label']);
        $this->assertStringNotContainsString($longValue, $step['label']);
    }

    // ── Preview scope detection ─────────────────────────────────────────

    public function testPreviewDetectsShopGroupScope(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_MODE', 'value' => 'live', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_MODE', 'value' => null, 'id_shop' => null, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config', ['module' => 'colissimo']);

        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertStringContainsString('group 1', $result['steps'][0]['label']);
    }

    public function testNoBlockingNullWhenGlobalIsNull(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_MODE', 'value' => null, 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_MODE', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertCount(0, $result['modules']);
        $this->assertSame(0, $result['changes']['total_issues']);
    }

    public function testNoBlockingNullWhenGlobalIsEmpty(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_MODE', 'value' => '', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_MODE', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertCount(0, $result['modules']);
    }

    public function testShopValueNonNullIsNotBlockingNull(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_MODE', 'value' => 'live', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_MODE', 'value' => 'sandbox', 'id_shop' => 2, 'id_shop_group' => 0, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('module_config');

        $this->assertCount(0, $result['modules']);
    }

    // ── Apply ───────────────────────────────────────────────────────────

    public function testApplyWithoutModuleReturnsError(): void
    {
        $result = $this->fixer->apply('module_config');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('module', $result['error']);
    }

    public function testApplyWithInvalidModuleNameReturnsError(): void
    {
        $result = $this->fixer->apply('module_config', ['module' => 'drop table;']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function testApplyWithModuleFixesBlockingNulls(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'mylogin', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0],
            ],
            [],
        ]);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'low977configuration',
                $this->callback(function (array $data) {
                    return $data['value'] === 'mylogin' && isset($data['date_upd']);
                }),
                ['id_configuration' => 11]
            );

        $result = $this->fixer->apply('module_config', ['module' => 'colissimo']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['rows_affected']);
        $this->assertSame(0, $result['remaining_issues']);
        $this->assertSame('colissimo', $result['module']);
    }

    public function testApplyFixesMultipleBlockingNulls(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'login', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 1, 'id_shop_group' => 0],
                ['id_configuration' => 12, 'name' => 'COLISSIMO_LOGIN', 'value' => '', 'id_shop' => 2, 'id_shop_group' => 0],
                ['id_configuration' => 20, 'name' => 'COLISSIMO_MODE', 'value' => 'live', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 21, 'name' => 'COLISSIMO_MODE', 'value' => null, 'id_shop' => 1, 'id_shop_group' => 0],
            ],
            [],
        ]);

        $this->connection->expects($this->exactly(3))
            ->method('update');

        $result = $this->fixer->apply('module_config', ['module' => 'colissimo']);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['rows_affected']);
    }

    public function testApplyWithNoIssuesReturnsSuccess(): void
    {
        $this->mockFetchAllSequence([
            [],
        ]);

        $result = $this->fixer->apply('module_config', ['module' => 'colissimo']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['rows_affected']);
    }

    public function testApplyWithRemainingIssuesReturnsFalse(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'login', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0],
            ],
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_LOGIN', 'value' => 'login', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_LOGIN', 'value' => null, 'id_shop' => 2, 'id_shop_group' => 0],
            ],
        ]);

        $this->connection->method('update');

        $result = $this->fixer->apply('module_config', ['module' => 'colissimo']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['rows_affected']);
        $this->assertSame(1, $result['remaining_issues']);
    }

    public function testApplyHandlesException(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $result = $this->fixer->apply('module_config', ['module' => 'colissimo']);

        $this->assertFalse($result['success']);
        $this->assertSame('module_config', $result['type']);
        $this->assertStringContainsString('DB connection lost', $result['error']);
    }

    // ── Unsupported types ───────────────────────────────────────────────

    public function testPreviewUnsupportedTypeReturnsError(): void
    {
        $result = $this->fixer->preview('unknown_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testApplyUnsupportedTypeReturnsError(): void
    {
        $result = $this->fixer->apply('unknown_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    // ── getSupportedTypes includes shop_override ────────────────────────────

    public function testGetSupportedTypesIncludesShopOverride(): void
    {
        $this->assertContains('shop_override', $this->fixer->getSupportedTypes());
    }

    // ── shop_override preview: no module ───────────────────────────────────

    public function testPreviewShopOverrideWithoutModuleSingleModuleAutoSelects(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override');

        $this->assertTrue($result['success']);
        $this->assertSame('shop_override', $result['type']);
        $this->assertSame('paypal', $result['selected_module']);
        $this->assertSame(1, $result['changes']['keys_affected']);
        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertCount(1, $result['steps']);
    }

    public function testPreviewShopOverrideWithoutModuleNoIssues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override');

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['modules']);
        $this->assertSame(0, $result['changes']['total_issues']);
    }

    public function testPreviewShopOverrideWithoutModuleMultipleModules(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
                ['id_configuration' => 20, 'name' => 'STRIPE_OFFICIAL_MODE', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'stripe_official'],
                ['id_configuration' => 21, 'name' => 'STRIPE_OFFICIAL_MODE', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'stripe_official'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override');

        $this->assertCount(2, $result['modules']);
        $this->assertSame(2, $result['changes']['total_issues']);
    }

    // ── shop_override preview: with module ─────────────────────────────────

    public function testPreviewShopOverrideWithModuleReturnsDetailedPreview(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override', ['module' => 'paypal']);

        $this->assertTrue($result['success']);
        $this->assertSame('paypal', $result['selected_module']);
        $this->assertSame(1, $result['changes']['keys_affected']);
        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame('configuration', $result['steps'][0]['table']);
        $this->assertStringContainsString('shop 1', $result['steps'][0]['label']);
        $this->assertStringContainsString('"1"', $result['steps'][0]['label']);
        $this->assertStringContainsString('"0"', $result['steps'][0]['label']);
    }

    public function testPreviewShopOverrideSkipsNullShopValues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => null, 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override', ['module' => 'paypal']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['changes']['total_entries_to_fix']);
    }

    public function testPreviewShopOverrideSkipsMatchingValues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override', ['module' => 'paypal']);

        $this->assertSame(0, $result['changes']['total_entries_to_fix']);
    }

    public function testPreviewShopOverrideSkipsSensitiveKeys(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_CLIENT_ID', 'value' => 'global-client-id', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 11, 'name' => 'PAYPAL_CLIENT_ID', 'value' => 'shop1-client-id', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
                ['id_configuration' => 20, 'name' => 'PAYPAL_CLIENT_SECRET', 'value' => 'global-secret', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 21, 'name' => 'PAYPAL_CLIENT_SECRET', 'value' => 'shop1-secret', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
                ['id_configuration' => 30, 'name' => 'COLISSIMO_LOGIN', 'value' => 'global-login', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 31, 'name' => 'COLISSIMO_LOGIN', 'value' => 'shop1-login', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
                ['id_configuration' => 40, 'name' => 'COLISSIMO_ACCOUNT_NUMBER', 'value' => '12345', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 41, 'name' => 'COLISSIMO_ACCOUNT_NUMBER', 'value' => '67890', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
                // Behavioral keys — must still be detected (2 modules so summary view is returned)
                ['id_configuration' => 50, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'paypal'],
                ['id_configuration' => 51, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
                ['id_configuration' => 60, 'name' => 'COLISSIMO_RELAY_HOME', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 61, 'name' => 'COLISSIMO_RELAY_HOME', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['changes']['total_issues']);
        $this->assertCount(2, $result['modules']);
        foreach ($result['modules'] as $module) {
            $this->assertSame(1, $module['issue_count'], "Module {$module['name']} should have 1 issue");
        }
    }

    public function testPreviewShopOverrideSkipsShopGroupLevelEntries(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'COLISSIMO_RELAY_HOME', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null, 'module_name' => 'colissimo'],
                ['id_configuration' => 11, 'name' => 'COLISSIMO_RELAY_HOME', 'value' => '1', 'id_shop' => null, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
                ['id_configuration' => 12, 'name' => 'COLISSIMO_RELAY_HOME', 'value' => '1', 'id_shop' => 2, 'id_shop_group' => 1, 'module_name' => 'colissimo'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override', ['module' => 'colissimo']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['changes']['total_entries_to_fix']);
        $this->assertStringContainsString('shop 2', $result['steps'][0]['label']);
    }

    public function testPreviewShopOverrideSkipsWhenNoGlobalValue(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 11, 'name' => 'PAYPAL_BNPL_CART_PAGE', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1, 'module_name' => 'paypal'],
            ],
        ]);

        $result = $this->fixer->preview('shop_override', ['module' => 'paypal']);

        $this->assertSame(0, $result['changes']['total_entries_to_fix']);
    }

    public function testPreviewShopOverrideWithInvalidModuleReturnsError(): void
    {
        $this->mockFetchAllSequence([[]]);

        $result = $this->fixer->preview('shop_override', ['module' => 'invalid-module!']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    // ── shop_override apply ─────────────────────────────────────────────────

    public function testApplyShopOverrideWithoutModuleReturnsError(): void
    {
        $result = $this->fixer->apply('shop_override');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('module', $result['error']);
    }

    public function testApplyShopOverrideWithInvalidModuleReturnsError(): void
    {
        $result = $this->fixer->apply('shop_override', ['module' => 'drop table;']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function testApplyShopOverrideFixesOverridingShopValues(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1],
            ],
            [],
        ]);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'low977configuration',
                $this->callback(function (array $data) {
                    return $data['value'] === '0' && isset($data['date_upd']);
                }),
                ['id_configuration' => 11]
            );

        $result = $this->fixer->apply('shop_override', ['module' => 'paypal']);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['rows_affected']);
        $this->assertSame(0, $result['remaining_issues']);
        $this->assertSame('paypal', $result['module']);
    }

    public function testApplyShopOverrideFixesMultipleShopsAndKeys(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1],
                ['id_configuration' => 12, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 2, 'id_shop_group' => 1],
                ['id_configuration' => 20, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT_CART', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 21, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT_CART', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1],
            ],
            [],
        ]);

        $this->connection->expects($this->exactly(3))
            ->method('update');

        $result = $this->fixer->apply('shop_override', ['module' => 'paypal']);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['rows_affected']);
    }

    public function testApplyShopOverrideWithNoIssuesReturnsSuccess(): void
    {
        $this->mockFetchAllSequence([
            [],
        ]);

        $result = $this->fixer->apply('shop_override', ['module' => 'paypal']);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['rows_affected']);
    }

    public function testApplyShopOverrideWithRemainingIssuesReturnsFalse(): void
    {
        $this->mockFetchAllSequence([
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1],
            ],
            [
                ['id_configuration' => 10, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '0', 'id_shop' => null, 'id_shop_group' => null],
                ['id_configuration' => 11, 'name' => 'PAYPAL_EXPRESS_CHECKOUT_SHORTCUT', 'value' => '1', 'id_shop' => 1, 'id_shop_group' => 1],
            ],
        ]);

        $this->connection->method('update');

        $result = $this->fixer->apply('shop_override', ['module' => 'paypal']);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['rows_affected']);
        $this->assertSame(1, $result['remaining_issues']);
    }

    public function testApplyShopOverrideHandlesException(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->fixer->apply('shop_override', ['module' => 'paypal']);

        $this->assertFalse($result['success']);
        $this->assertSame('shop_override', $result['type']);
        $this->assertStringContainsString('DB error', $result['error']);
    }
}
