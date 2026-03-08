<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Service;

use Doctrine\DBAL\Connection;

/**
 * Base class for all fixer services
 */
abstract class AbstractFixer
{
    public function __construct(
        protected Connection $connection,
        protected string $prefix
    ) {
    }

    /**
     * @return array<string>
     */
    abstract public function getSupportedTypes(): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function preview(string $type, array $options = []): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function apply(string $type, array $options = []): array;

    /**
     * Build a parameterized exclusion clause for product IDs.
     * Returns an empty array when no products are excluded (guards against invalid NOT IN ()).
     *
     * @return array{0: string, 1: array<int>, 2: array<int>}|null [SQL clause, params, types] or null
     */
    protected function buildExclusionClause(string $column): ?array
    {
        if (!MultishopConstants::hasExcludedProducts()) {
            return null;
        }

        $ids = MultishopConstants::EXCLUDED_PRODUCTS;

        return [
            "AND {$column} NOT IN (?)",
            $ids,
            [Connection::PARAM_INT_ARRAY],
        ];
    }
}
