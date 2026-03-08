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

/**
 * Shared constants for the verify-multishop module
 */
final class MultishopConstants
{
    /**
     * Products to exclude from all operations (gift cards, virtual products, etc.)
     *
     * @var array<int>
     */
    public const EXCLUDED_PRODUCTS = [857];

    /**
     * Get SQL-safe excluded product IDs string for use in IN() clauses
     */
    public static function getExcludedIds(): string
    {
        return implode(',', self::EXCLUDED_PRODUCTS);
    }

    /**
     * Returns true when there are products to exclude (guards against invalid NOT IN ())
     */
    public static function hasExcludedProducts(): bool
    {
        return count(self::EXCLUDED_PRODUCTS) > 0;
    }
}
