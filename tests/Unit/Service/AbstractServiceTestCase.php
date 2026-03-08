<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for service tests with DBAL Connection mocking helpers
 */
abstract class AbstractServiceTestCase extends TestCase
{
    protected Connection&MockObject $connection;
    protected string $prefix = 'ps_';

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * Configure fetchOne to return values in order for sequential calls
     */
    protected function mockFetchOneSequence(array $values): void
    {
        $this->connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(...$values);
    }

    /**
     * Configure fetchAllAssociative to return values in order
     */
    protected function mockFetchAllSequence(array $values): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(...$values);
    }

    /**
     * Build a stock_available shop distribution row
     */
    protected function buildShopDistribution(
        int $idShop,
        int $count,
        int $totalQty,
        int $nullQty = 0
    ): array {
        return [
            'id_shop' => $idShop,
            'count' => $count,
            'total_quantity' => $totalQty,
            'avg_quantity' => $count > 0 ? $totalQty / $count : 0,
            'min_quantity' => 0,
            'max_quantity' => $totalQty,
            'unique_products' => $count,
            'null_quantities' => $nullQty,
        ];
    }
}
