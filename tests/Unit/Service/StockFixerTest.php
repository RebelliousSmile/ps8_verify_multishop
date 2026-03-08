<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\StockFixer;

class StockFixerTest extends AbstractServiceTestCase
{
    private StockFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new StockFixer($this->connection, $this->prefix);
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(
            ['stocks', 'duplicate_stocks', 'null_quantities', 'share_stock'],
            $this->fixer->getSupportedTypes()
        );
    }

    // === preview: stocks ===

    public function testPreviewStocksShowsCurrentState(): void
    {
        $this->mockFetchOneSequence([2, 500, 100]); // totalShops, shop1Count, wrongShopGroup

        $result = $this->fixer->preview('stocks');

        $this->assertTrue($result['success']);
        $this->assertSame('stocks', $result['type']);
        $this->assertSame(500, $result['changes']['shop1_entries']);
        $this->assertSame(100, $result['changes']['wrong_shop_group']);
    }

    // === preview: duplicate_stocks ===

    public function testPreviewDuplicateStocksShowsEstimate(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 2], ['id_shop' => 3],
        ]);
        $this->mockFetchOneSequence([500]); // shop1Count

        $result = $this->fixer->preview('duplicate_stocks');

        $this->assertTrue($result['success']);
        $this->assertSame('duplicate_stocks', $result['type']);
        $this->assertSame(500, $result['changes']['shop1_entries']);
        $this->assertSame(2, $result['changes']['target_shops']);
        $this->assertSame(1000, $result['changes']['estimated_inserts']);
    }

    // === preview: null_quantities ===

    public function testPreviewNullQuantitiesWithRestorableData(): void
    {
        $this->mockFetchOneSequence([5]); // null count
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop' => 1, 'nb' => 100, 'total_qty' => 500],
        ]);

        $result = $this->fixer->preview('null_quantities');

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['changes']['null_quantities_shop0']);
        $this->assertStringContainsString('Smart copy', $result['changes']['strategy']);
    }

    public function testPreviewNullQuantitiesNoRestoreAvailable(): void
    {
        $this->mockFetchOneSequence([3]); // null count
        $this->connection->method('fetchAllAssociative')->willReturn([]); // no other shops

        $result = $this->fixer->preview('null_quantities');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('mise à 0', $result['changes']['strategy']);
    }

    // === preview: share_stock ===

    public function testPreviewShareStockShowsChangesNeeded(): void
    {
        $this->mockFetchOneSequence([1, 200, 1, 50]); // shareStockDisabled, nonGlobalStocks, shopGroupId, wrongShopGroup

        $result = $this->fixer->preview('share_stock');

        $this->assertTrue($result['success']);
        $this->assertSame('share_stock', $result['type']);
        $this->assertSame(1, $result['changes']['shop_groups_to_fix']);
        $this->assertSame(200, $result['changes']['stocks_to_convert_to_global']);
    }

    // === apply: stocks ===

    public function testApplyStocksConvertsShop1ToGlobal(): void
    {
        // fetchOne: countShop1=500, countGlobal=0, wrongShopGroup=100, newCountGlobal=500, newCountShop1=0
        $this->mockFetchOneSequence([500, 0, 100, 500, 0]);
        $this->connection->method('executeStatement')->willReturn(500);

        $result = $this->fixer->apply('stocks');

        $this->assertTrue($result['success']);
        $this->assertSame('stocks', $result['type']);
    }

    public function testApplyStocksHandlesException(): void
    {
        $this->connection->method('fetchOne')
            ->willThrowException(new \Exception('Connection lost'));

        $result = $this->fixer->apply('stocks');

        $this->assertFalse($result['success']);
        $this->assertSame('Connection lost', $result['error']);
    }

    // === apply: duplicate_stocks ===

    public function testApplyDuplicateStocksInsertsForAllShops(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn([2, 3]); // shops except 1
        $this->connection->method('executeStatement')->willReturn(100);
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls(100, 100); // ROW_COUNT for each shop

        $result = $this->fixer->apply('duplicate_stocks');

        $this->assertTrue($result['success']);
        $this->assertSame('duplicate_stocks', $result['type']);
        $this->assertSame(200, $result['inserted']);
    }

    // === apply: null_quantities ===

    public function testApplyNullQuantitiesReturnsEarlyWhenNoneNull(): void
    {
        $this->mockFetchOneSequence([0]); // nbNull = 0

        $result = $this->fixer->apply('null_quantities');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['restored_from_shops']);
        $this->assertSame(0, $result['set_to_zero']);
    }

    public function testApplyNullQuantitiesRestoresFromOtherShops(): void
    {
        // fetchOne: nbNull=2, then individual source queries + verify
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                2,     // nbNull
                10,    // sourceQty for entry 1
                false, // no source for entry 2
                0      // remainingNull after fix
            );

        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // otherShopsStocks (not empty = smart copy mode)
                [['id_shop' => 1, 'nb' => 100, 'total_qty' => 500]],
                // nullEntries
                [
                    ['id_stock_available' => 1, 'id_product' => 10, 'id_product_attribute' => 0],
                    ['id_stock_available' => 2, 'id_product' => 20, 'id_product_attribute' => 0],
                ]
            );

        $this->connection->method('executeStatement')->willReturn(1);

        $result = $this->fixer->apply('null_quantities');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['initial_null']);
        $this->assertSame(1, $result['restored_from_shops']);
        $this->assertSame(1, $result['set_to_zero']);
    }

    // === apply: share_stock ===

    public function testApplyShareStockEnablesAndConverts(): void
    {
        // executeStatement: groupUpdated, then stocksConverted, then groupFixed
        $this->connection->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1, 200, 50);
        // fetchOne: shopGroupId
        $this->mockFetchOneSequence([1]);

        $result = $this->fixer->apply('share_stock');

        $this->assertTrue($result['success']);
        $this->assertSame('share_stock', $result['type']);
        $this->assertSame(1, $result['shop_group_updated']);
        $this->assertSame(200, $result['stocks_converted_to_global']);
        $this->assertSame(50, $result['shop_group_fixed']);
    }

    public function testApplyShareStockHandlesException(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new \Exception('Permission denied'));

        $result = $this->fixer->apply('share_stock');

        $this->assertFalse($result['success']);
        $this->assertSame('Permission denied', $result['error']);
    }
}
