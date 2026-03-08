<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\ShopGroupFixer;

class ShopGroupFixerTest extends AbstractServiceTestCase
{
    private ShopGroupFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new ShopGroupFixer($this->connection, $this->prefix);
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(['shop_group_sharing'], $this->fixer->getSupportedTypes());
    }

    public function testPreviewDetectsGroupsToFix(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop_group' => 1, 'name' => 'Default', 'share_customer' => 0, 'share_order' => 0, 'share_stock' => 0],
        ]);

        $result = $this->fixer->preview('shop_group_sharing');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['changes']['groups_to_fix']);
        $this->assertNotEmpty($result['details']);
    }

    public function testPreviewNoFixNeeded(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop_group' => 1, 'name' => 'Default', 'share_customer' => 1, 'share_order' => 1, 'share_stock' => 1],
        ]);

        $result = $this->fixer->preview('shop_group_sharing');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['changes']['groups_to_fix']);
        $this->assertEmpty($result['details']);
    }

    public function testPreviewReturnsUnsupportedForUnknownType(): void
    {
        $result = $this->fixer->preview('unknown');

        $this->assertFalse($result['success']);
        $this->assertSame('Unsupported type', $result['error']);
    }

    public function testApplyEnablesAllSharing(): void
    {
        $this->connection->method('executeStatement')->willReturn(1);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop_group' => 1, 'name' => 'Default', 'share_customer' => 1, 'share_order' => 1, 'share_stock' => 1],
        ]);

        $result = $this->fixer->apply('shop_group_sharing');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['all_shares_enabled']);
    }

    public function testApplyReportsFailureOnPartialUpdate(): void
    {
        $this->connection->method('executeStatement')->willReturn(1);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id_shop_group' => 1, 'name' => 'Default', 'share_customer' => 1, 'share_order' => 0, 'share_stock' => 1],
        ]);

        $result = $this->fixer->apply('shop_group_sharing');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['all_shares_enabled']);
    }

    public function testApplyHandlesException(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->fixer->apply('shop_group_sharing');

        $this->assertFalse($result['success']);
        $this->assertSame('DB error', $result['error']);
    }
}
