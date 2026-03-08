<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use ScVerifyMultishop\Service\CarrierFixer;
use ScVerifyMultishop\Service\ConfigFixer;
use ScVerifyMultishop\Service\ContentFixer;
use ScVerifyMultishop\Service\FixerDispatcher;
use ScVerifyMultishop\Service\ProductFixer;
use ScVerifyMultishop\Service\ShopGroupFixer;
use ScVerifyMultishop\Service\StockFixer;

class FixerDispatcherTest extends AbstractServiceTestCase
{
    private ShopGroupFixer&MockObject $shopGroup;
    private CarrierFixer&MockObject $carrier;
    private ProductFixer&MockObject $product;
    private StockFixer&MockObject $stock;
    private ContentFixer&MockObject $content;
    private ConfigFixer&MockObject $config;
    private FixerDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopGroup = $this->createMock(ShopGroupFixer::class);
        $this->shopGroup->method('getSupportedTypes')->willReturn(['shop_group_sharing']);

        $this->carrier = $this->createMock(CarrierFixer::class);
        $this->carrier->method('getSupportedTypes')->willReturn(['carriers_payments']);

        $this->product = $this->createMock(ProductFixer::class);
        $this->product->method('getSupportedTypes')->willReturn(['products']);

        $this->stock = $this->createMock(StockFixer::class);
        $this->stock->method('getSupportedTypes')->willReturn(['stocks', 'duplicate_stocks', 'null_quantities', 'share_stock']);

        $this->content = $this->createMock(ContentFixer::class);
        $this->content->method('getSupportedTypes')->willReturn(['image_shop', 'cms', 'meta']);

        $this->config = $this->createMock(ConfigFixer::class);
        $this->config->method('getSupportedTypes')->willReturn(['module_config', 'shop_override']);

        $this->dispatcher = new FixerDispatcher(
            $this->shopGroup,
            $this->carrier,
            $this->product,
            $this->stock,
            $this->content,
            $this->config
        );
    }

    public function testPreviewReturnsErrorForUnknownType(): void
    {
        $result = $this->dispatcher->preview('nonexistent_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testApplyReturnsErrorForUnknownType(): void
    {
        $result = $this->dispatcher->apply('nonexistent_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testPreviewDispatchesToShopGroupFixer(): void
    {
        $expected = ['success' => true, 'type' => 'shop_group_sharing'];
        $this->shopGroup->expects($this->once())
            ->method('preview')
            ->with('shop_group_sharing')
            ->willReturn($expected);

        $result = $this->dispatcher->preview('shop_group_sharing');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToCarrierFixer(): void
    {
        $expected = ['success' => true, 'type' => 'carriers_payments'];
        $this->carrier->expects($this->once())
            ->method('preview')
            ->with('carriers_payments')
            ->willReturn($expected);

        $result = $this->dispatcher->preview('carriers_payments');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToProductFixer(): void
    {
        $expected = ['success' => true, 'type' => 'products'];
        $this->product->expects($this->once())
            ->method('preview')
            ->with('products')
            ->willReturn($expected);

        $result = $this->dispatcher->preview('products');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToStockFixer(): void
    {
        $expected = ['success' => true, 'type' => 'stocks'];
        $this->stock->expects($this->once())
            ->method('preview')
            ->with('stocks')
            ->willReturn($expected);

        $result = $this->dispatcher->preview('stocks');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToContentFixer(): void
    {
        $expected = ['success' => true, 'type' => 'image_shop'];
        $this->content->expects($this->once())
            ->method('preview')
            ->with('image_shop')
            ->willReturn($expected);

        $result = $this->dispatcher->preview('image_shop');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToConfigFixerWithOptions(): void
    {
        $options = ['module' => 'colissimo'];
        $expected = ['success' => true, 'type' => 'module_config'];
        $this->config->expects($this->once())
            ->method('preview')
            ->with('module_config', $options)
            ->willReturn($expected);

        $result = $this->dispatcher->preview('module_config', $options);

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToConfigFixerWithoutOptions(): void
    {
        $expected = ['success' => true, 'type' => 'module_config', 'modules' => []];
        $this->config->expects($this->once())
            ->method('preview')
            ->with('module_config', [])
            ->willReturn($expected);

        $result = $this->dispatcher->preview('module_config');

        $this->assertSame($expected, $result);
    }

    public function testPreviewDispatchesToConfigFixerShopOverride(): void
    {
        $expected = ['success' => true, 'type' => 'shop_override', 'modules' => []];
        $this->config->expects($this->once())
            ->method('preview')
            ->with('shop_override', [])
            ->willReturn($expected);

        $result = $this->dispatcher->preview('shop_override');

        $this->assertSame($expected, $result);
    }

    public function testApplyDispatchesAndReturnsResult(): void
    {
        $expected = ['success' => true, 'type' => 'cms'];
        $this->content->expects($this->once())
            ->method('apply')
            ->with('cms')
            ->willReturn($expected);

        $result = $this->dispatcher->apply('cms');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cache_cleared']);
    }

    public function testApplyPassesOptionsToConfigFixer(): void
    {
        $options = ['module' => 'colissimo'];
        $expected = ['success' => true, 'type' => 'module_config', 'rows_affected' => 5];
        $this->config->expects($this->once())
            ->method('apply')
            ->with('module_config', $options)
            ->willReturn($expected);

        $result = $this->dispatcher->apply('module_config', $options);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cache_cleared']);
    }

    public function testApplyDoesNotClearCacheOnFailure(): void
    {
        $this->stock->expects($this->once())
            ->method('apply')
            ->with('stocks')
            ->willReturn(['success' => false, 'error' => 'DB error']);

        $result = $this->dispatcher->apply('stocks');

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('cache_cleared', $result);
    }

    /**
     * @dataProvider allTypesProvider
     */
    public function testAllTypesAreRegistered(string $type): void
    {
        // Configure all fixers to return a simple result
        $this->shopGroup->method('preview')->willReturn(['success' => true]);
        $this->carrier->method('preview')->willReturn(['success' => true]);
        $this->product->method('preview')->willReturn(['success' => true]);
        $this->stock->method('preview')->willReturn(['success' => true]);
        $this->content->method('preview')->willReturn(['success' => true]);
        $this->config->method('preview')->willReturn(['success' => true]);

        $result = $this->dispatcher->preview($type);

        // Should NOT return "Unknown fix type" error
        $this->assertArrayNotHasKey('error', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allTypesProvider(): array
    {
        return [
            'shop_group_sharing' => ['shop_group_sharing'],
            'carriers_payments' => ['carriers_payments'],
            'products' => ['products'],
            'stocks' => ['stocks'],
            'duplicate_stocks' => ['duplicate_stocks'],
            'null_quantities' => ['null_quantities'],
            'share_stock' => ['share_stock'],
            'image_shop' => ['image_shop'],
            'cms' => ['cms'],
            'meta' => ['meta'],
            'module_config' => ['module_config'],
            'shop_override' => ['shop_override'],
        ];
    }
}
