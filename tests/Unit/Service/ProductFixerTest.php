<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\ProductFixer;

class ProductFixerTest extends AbstractServiceTestCase
{
    private ProductFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new ProductFixer($this->connection, $this->prefix);
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(['products'], $this->fixer->getSupportedTypes());
    }

    public function testPreviewReturnsUnsupportedForUnknownType(): void
    {
        $result = $this->fixer->preview('unknown');

        $this->assertFalse($result['success']);
    }

    public function testPreviewProductsReturnsSteps(): void
    {
        $this->mockFetchOneSequence([
            2,    // totalShops
            1,    // sourceShopId
            100,  // totalProducts
            2,    // totalLangs
            200,  // productLangSource
            100,  // productLangMissing
            50,   // productShopMissing
            30,   // totalCombinations
            20,   // combinationsMissing
            5,    // defaultOnMissing
            10,   // totalAttributes
            5,    // attributeShopMissing
            15,   // totalCategories
            30,   // categoryLangSource
            10,   // categoryLangMissing
            5,    // categoryShopMissing
            3,    // specificPricesToConvert
            8,    // totalFeatures
            4,    // featureShopMissing
            0,    // priceZeroCount
            0,    // specificPriceZero
            0,    // specificPrice100Off
        ]);

        $result = $this->fixer->preview('products');

        $this->assertTrue($result['success']);
        $this->assertSame('products', $result['type']);
        $this->assertSame(2, $result['changes']['total_shops']);
        $this->assertSame(1, $result['changes']['source_shop']);
        $this->assertCount(9, $result['steps']);
        $this->assertSame('product_lang', $result['steps'][0]['table']);
        $this->assertSame('product_shop', $result['steps'][1]['table']);
        // Step 3 should combine missing + defaultOnMissing
        $this->assertSame(25, $result['steps'][2]['missing']); // 20 + 5
        $this->assertSame('product_attribute_shop', $result['steps'][2]['table']);
    }

    public function testApplyProductsHandlesException(): void
    {
        $this->connection->method('fetchFirstColumn')
            ->willThrowException(new \Exception('Connection lost'));

        $result = $this->fixer->apply('products');

        $this->assertFalse($result['success']);
        $this->assertSame('Connection lost', $result['error']);
    }

    public function testApplyReturnsUnsupportedForUnknownType(): void
    {
        $result = $this->fixer->apply('unknown');

        $this->assertFalse($result['success']);
    }
}
