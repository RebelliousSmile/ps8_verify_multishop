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
 * Fixer for product-related multishop data
 */
class ProductFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['products'];
    }

    public function preview(string $type, array $options = []): array
    {
        return match ($type) {
            'products' => $this->previewProducts(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type, array $options = []): array
    {
        return match ($type) {
            'products' => $this->fixProducts(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    /**
     * Safe INSERT that quotes column names (handles MySQL reserved words like 'condition', 'from', 'to')
     *
     * @param array<string, mixed> $data Column => value pairs
     */
    private function safeInsert(string $table, array $data): void
    {
        $columns = array_map(fn ($col) => "`{$col}`", array_keys($data));
        $placeholders = array_fill(0, count($data), '?');

        $sql = sprintf(
            'INSERT IGNORE INTO `%s%s` (%s) VALUES (%s)',
            $this->prefix,
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->connection->executeStatement($sql, array_values($data));
    }

    private function previewProducts(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $sourceShopId = (int) $this->connection->fetchOne(
            "SELECT MIN(id_shop) FROM {$this->prefix}shop WHERE active = 1"
        );

        $targetShopsCount = $totalShops - 1;
        $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;

        $totalProducts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}product WHERE active = 1 AND id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $totalLangs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}lang WHERE active = 1"
        );

        // Step 1: product_lang
        $productLangSource = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}product_lang pl
            INNER JOIN {$this->prefix}product p ON pl.id_product = p.id_product
            WHERE pl.id_shop = ? AND p.active = 1 AND p.id_product NOT IN (?)",
            [$sourceShopId, $excludedIds],
            [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]
        );

        $productLangMissing = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM {$this->prefix}product_lang pl_source
            INNER JOIN {$this->prefix}product p ON pl_source.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            WHERE pl_source.id_shop = ?
            AND p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND s.id_shop != ?
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_lang pl
                WHERE pl.id_product = pl_source.id_product
                AND pl.id_lang = pl_source.id_lang
                AND pl.id_shop = s.id_shop
            )",
            [$sourceShopId, $excludedIds, $sourceShopId],
            [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT]
        );

        // Step 2: product_shop
        $productShopMissing = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM {$this->prefix}product p
            CROSS JOIN {$this->prefix}shop s
            WHERE p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_shop ps
                WHERE ps.id_product = p.id_product AND ps.id_shop = s.id_shop
            )",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        // Step 3: product_attribute_shop
        $totalCombinations = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT pa.id_product_attribute)
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            WHERE p.active = 1 AND p.id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );
        $combinationsMissing = (int) $this->connection->fetchOne(
            "SELECT COUNT(*)
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            WHERE p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_attribute_shop pas
                WHERE pas.id_product_attribute = pa.id_product_attribute
                AND pas.id_shop = s.id_shop
            )",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        // default_on missing: products×shops where entries exist but none has default_on=1
        $defaultOnMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT pa.id_product, pas.id_shop
                FROM {$this->prefix}product_attribute pa
                INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
                INNER JOIN {$this->prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute
                WHERE p.active = 1 AND p.id_product NOT IN (?)
                GROUP BY pa.id_product, pas.id_shop
                HAVING SUM(CASE WHEN pas.default_on = 1 THEN 1 ELSE 0 END) = 0
            ) as m
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $combinationsTotal = $combinationsMissing + $defaultOnMissing;

        // Step 4: attribute_shop
        $totalAttributes = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}attribute"
        );
        $attributeShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT a.id_attribute
                FROM {$this->prefix}attribute a
                LEFT JOIN {$this->prefix}attribute_shop ash ON a.id_attribute = ash.id_attribute
                GROUP BY a.id_attribute
                HAVING COUNT(DISTINCT ash.id_shop) < ?
            ) as m
        ", [$totalShops]);

        // Step 5: category_lang
        $totalCategories = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}category WHERE active = 1"
        );
        $categoryLangSource = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}category_lang cl
            INNER JOIN {$this->prefix}category c ON cl.id_category = c.id_category
            WHERE cl.id_shop = ? AND c.active = 1
        ", [$sourceShopId]);
        $categoryLangMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT c.id_category
                FROM {$this->prefix}category c
                LEFT JOIN {$this->prefix}category_lang cl ON c.id_category = cl.id_category
                WHERE c.active = 1
                GROUP BY c.id_category
                HAVING COUNT(DISTINCT cl.id_shop) < ?
            ) as m
        ", [$totalShops]);

        // Step 6: category_shop
        $categoryShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT c.id_category
                FROM {$this->prefix}category c
                LEFT JOIN {$this->prefix}category_shop cs ON c.id_category = cs.id_category
                WHERE c.active = 1
                GROUP BY c.id_category
                HAVING COUNT(DISTINCT cs.id_shop) < ?
            ) as m
        ", [$totalShops]);

        // Step 7: specific_price
        $specificPricesToConvert = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}specific_price
            WHERE id_shop > 0 AND id_product > 0
        ");

        // Step 8: feature_shop
        $totalFeatures = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}feature"
        );
        $featureShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT f.id_feature
                FROM {$this->prefix}feature f
                LEFT JOIN {$this->prefix}feature_shop fs ON f.id_feature = fs.id_feature
                GROUP BY f.id_feature
                HAVING COUNT(DISTINCT fs.id_shop) < ?
            ) as m
        ", [$totalShops]);

        // Step 9: price anomaly check
        // Exclude products where product.price = 0 (combination-level pricing — normal behavior)
        $priceZeroCount = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT ps.id_product)
            FROM {$this->prefix}product_shop ps
            INNER JOIN {$this->prefix}product p ON p.id_product = ps.id_product
            WHERE ps.price = 0
            AND p.price > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $specificPriceZero = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT sp.id_product)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.price = 0
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $specificPrice100Off = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT sp.id_product)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.reduction_type = 'percentage'
            AND sp.reduction >= 1
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $priceAnomalyTotal = $priceZeroCount + $specificPriceZero + $specificPrice100Off;

        return [
            'success' => true,
            'type' => 'products',
            'description' => 'Duplication complète des données produits vers tous les shops',
            'changes' => [
                'total_shops' => $totalShops,
                'source_shop' => $sourceShopId,
                'target_shops' => $targetShopsCount,
            ],
            'steps' => [
                [
                    'step' => 1,
                    'table' => 'product_lang',
                    'label' => 'Traductions produits',
                    'source_count' => $productLangSource,
                    'missing' => $productLangMissing,
                    'status' => $productLangMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 2,
                    'table' => 'product_shop',
                    'label' => 'Associations produit-shop',
                    'total_products' => $totalProducts,
                    'missing' => $productShopMissing,
                    'status' => $productShopMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 3,
                    'table' => 'product_attribute_shop',
                    'label' => 'Déclinaisons (combinaisons)',
                    'total_combinations' => $totalCombinations,
                    'missing' => $combinationsTotal,
                    'detail' => sprintf(
                        'Entrées manquantes: %d, default_on manquant: %d',
                        $combinationsMissing,
                        $defaultOnMissing
                    ),
                    'status' => $combinationsTotal > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 4,
                    'table' => 'attribute_shop',
                    'label' => 'Attributs',
                    'total_attributes' => $totalAttributes,
                    'missing' => $attributeShopMissing,
                    'status' => $attributeShopMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 5,
                    'table' => 'category_lang',
                    'label' => 'Traductions catégories',
                    'source_count' => $categoryLangSource,
                    'missing' => $categoryLangMissing,
                    'status' => $categoryLangMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 6,
                    'table' => 'category_shop',
                    'label' => 'Associations catégorie-shop',
                    'total_categories' => $totalCategories,
                    'missing' => $categoryShopMissing,
                    'status' => $categoryShopMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 7,
                    'table' => 'specific_price',
                    'label' => 'Promotions (prix spécifiques)',
                    'to_convert' => $specificPricesToConvert,
                    'action' => 'Conversion vers global (id_shop=0)',
                    'status' => $specificPricesToConvert > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 8,
                    'table' => 'feature_shop',
                    'label' => 'Caractéristiques',
                    'total_features' => $totalFeatures,
                    'missing' => $featureShopMissing,
                    'status' => $featureShopMissing > 0 ? 'pending' : 'ok',
                ],
                [
                    'step' => 9,
                    'table' => 'product_shop',
                    'label' => 'Anomalies prix (affichage 0€)',
                    'missing' => $priceAnomalyTotal,
                    'detail' => sprintf(
                        'product_shop.price=0: %d, specific_price.price=0: %d, réduction 100%%: %d',
                        $priceZeroCount,
                        $specificPriceZero,
                        $specificPrice100Off
                    ),
                    'status' => $priceAnomalyTotal > 0 ? 'pending' : 'ok',
                ],
            ],
        ];
    }

    private function fixProducts(): array
    {
        try {
            $results = [
                'success' => true,
                'type' => 'products',
            ];
            $shopsDetail = [];

            $shops = $this->connection->fetchFirstColumn(
                "SELECT id_shop FROM {$this->prefix}shop WHERE active = 1 ORDER BY id_shop"
            );
            $sourceShopId = $shops[0] ?? 1;

            $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;

            $extractResult = function (array $result, string $key) use (&$results, &$shopsDetail) {
                if (isset($result['total'])) {
                    $results[$key] = $result['total'];
                    if (!empty($result['by_shop'])) {
                        $shopsDetail[$key] = $result['by_shop'];
                    }
                } else {
                    $results[$key] = $result;
                }
            };

            $extractResult($this->fixProductLang($shops, $sourceShopId, $excludedIds), 'product_lang');
            $extractResult($this->fixProductShop($shops, $excludedIds), 'product_shop');
            $extractResult($this->syncProductShopData($sourceShopId, $excludedIds), 'product_shop_sync');
            $results['product_attribute_shop'] = $this->fixProductAttributeShop($shops, $excludedIds);
            $results['attribute_shop'] = $this->fixAttributeShop($shops, $sourceShopId);
            $results['category_lang'] = $this->fixCategoryLang($shops, $sourceShopId);
            $results['specific_price'] = $this->fixSpecificPrice($shops, $sourceShopId);
            $results['feature_product'] = $this->fixFeatureProduct($shops);
            $results['feature_shop'] = $this->fixFeatureShop($shops, $sourceShopId);
            $results['product_carrier'] = $this->fixProductCarrier($shops);
            $results['category_shop'] = $this->fixCategoryShop($shops);

            if (!empty($shopsDetail)) {
                $results['shops_detail'] = $shopsDetail;
            }

            return $results;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'products',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int> $excludedIds
     * @return array{total: int, by_shop: array<int, int>}
     */
    private function fixProductLang(array $shops, int $sourceShopId, array $excludedIds): array
    {
        $totalInserted = 0;
        $byShop = [];

        foreach ($shops as $targetShopId) {
            if ($targetShopId == $sourceShopId) {
                continue;
            }

            $this->connection->executeStatement(
                "INSERT IGNORE INTO {$this->prefix}product_lang
                (id_product, id_lang, id_shop, description, description_short, link_rewrite,
                 meta_description, meta_keywords, meta_title, name, available_now, available_later,
                 delivery_in_stock, delivery_out_stock)
                SELECT
                    pl.id_product,
                    pl.id_lang,
                    ? as id_shop,
                    pl.description,
                    pl.description_short,
                    pl.link_rewrite,
                    pl.meta_description,
                    pl.meta_keywords,
                    pl.meta_title,
                    pl.name,
                    pl.available_now,
                    pl.available_later,
                    pl.delivery_in_stock,
                    pl.delivery_out_stock
                FROM {$this->prefix}product_lang pl
                INNER JOIN {$this->prefix}product p ON pl.id_product = p.id_product
                WHERE pl.id_shop = ?
                AND p.active = 1
                AND p.id_product NOT IN (?)",
                [$targetShopId, $sourceShopId, $excludedIds],
                [\PDO::PARAM_INT, \PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]
            );

            $inserted = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
            $byShop[$targetShopId] = $inserted;
            $totalInserted += $inserted;
        }

        return ['total' => $totalInserted, 'by_shop' => $byShop];
    }

    /**
     * Fix product_shop - Batch INSERT...SELECT with CROSS JOIN (was N+1: product x shop)
     * Note: Uses dynamic column list from INFORMATION_SCHEMA because 'condition' is a MySQL reserved word
     *
     * @param array<int> $excludedIds
     * @return array{total: int, by_shop: array<int, int>}
     */
    private function fixProductShop(array $shops, array $excludedIds): array
    {
        $columns = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            ["{$this->prefix}product_shop"]
        );

        $selectCols = [];
        foreach ($columns as $col) {
            if ($col === 'id_shop') {
                $selectCols[] = 's.id_shop';
            } else {
                $selectCols[] = "ps_source.`{$col}`";
            }
        }

        $quotedCols = array_map(fn ($c) => "`{$c}`", $columns);
        $insertCols = implode(', ', $quotedCols);
        $selectClause = implode(', ', $selectCols);

        $inserted = (int) $this->connection->executeStatement(
            "INSERT IGNORE INTO {$this->prefix}product_shop ({$insertCols})
            SELECT {$selectClause}
            FROM {$this->prefix}product p
            CROSS JOIN {$this->prefix}shop s
            INNER JOIN {$this->prefix}product_shop ps_source ON (
                ps_source.id_product = p.id_product
                AND ps_source.id_shop = (
                    SELECT MIN(ps2.id_shop) FROM {$this->prefix}product_shop ps2
                    WHERE ps2.id_product = p.id_product
                )
            )
            WHERE p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_shop ps_existing
                WHERE ps_existing.id_product = p.id_product AND ps_existing.id_shop = s.id_shop
            )",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        return ['total' => $inserted, 'by_shop' => []];
    }

    /**
     * Fix price anomalies causing 0€ display.
     * Case 1: product_shop.price=0 but source shop has price > 0 → copy from source
     * Case 2: product_shop.price=0 everywhere → copy from product.price base table
     * Case 3: specific_price.price=0 (explicit override to 0€) → set to -1 (no override)
     * Case 4: specific_price.reduction=100% → delete these entries
     *
     * @param array<int> $excludedIds
     * @return array{total: int, by_shop: array<int, int>}
     */
    private function syncProductShopData(int $sourceShopId, array $excludedIds): array
    {
        $totalUpdated = 0;

        $columns = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            ["{$this->prefix}product_shop"]
        );

        $setClauses = [];
        foreach ($columns as $col) {
            if ($col === 'id_product' || $col === 'id_shop') {
                continue;
            }
            $setClauses[] = "ps.`{$col}` = ps_source.`{$col}`";
        }
        $setClause = implode(', ', $setClauses);

        $totalUpdated += (int) $this->connection->executeStatement("
            UPDATE {$this->prefix}product_shop ps
            INNER JOIN {$this->prefix}product_shop ps_source ON (
                ps_source.id_product = ps.id_product
                AND ps_source.id_shop = ?
            )
            INNER JOIN {$this->prefix}product p ON p.id_product = ps.id_product
            SET {$setClause}
            WHERE ps.id_shop != ?
            AND ps.price = 0
            AND ps_source.price > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)",
            [$sourceShopId, $sourceShopId, $excludedIds],
            [\PDO::PARAM_INT, \PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]
        );

        $totalUpdated += (int) $this->connection->executeStatement("
            UPDATE {$this->prefix}product_shop ps
            INNER JOIN {$this->prefix}product p ON p.id_product = ps.id_product
            SET ps.price = p.price,
                ps.wholesale_price = p.wholesale_price,
                ps.ecotax = p.ecotax,
                ps.unity = p.unity,
                ps.unit_price_ratio = p.unit_price_ratio
            WHERE ps.price = 0
            AND p.price > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $totalUpdated += (int) $this->connection->executeStatement("
            UPDATE {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            SET sp.price = -1
            WHERE sp.price = 0
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $totalUpdated += (int) $this->connection->executeStatement("
            DELETE sp FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.reduction_type = 'percentage'
            AND sp.reduction >= 1
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        return ['total' => $totalUpdated, 'by_shop' => []];
    }

    /**
     * Fix product_attribute_shop - Batch INSERT...SELECT (was N+1: attribute x shop)
     *
     * Part 0: Fix corrupted rows with id_product=0 (from previous buggy inserts)
     * Part 1: Copy from source shop to all target shops (INSERT...SELECT with CROSS JOIN)
     * Part 2: For combinations without any source data, insert defaults
     * Part 3: Fix default_on for products×shops where none has default_on=1
     *
     * @param array<int> $excludedIds
     */
    private function fixProductAttributeShop(array $shops, array $excludedIds): int
    {
        $this->connection->executeStatement(
            "UPDATE {$this->prefix}product_attribute_shop pas
            INNER JOIN {$this->prefix}product_attribute pa ON pa.id_product_attribute = pas.id_product_attribute
            SET pas.id_product = pa.id_product
            WHERE pas.id_product = 0"
        );

        $part1 = (int) $this->connection->executeStatement(
            "INSERT IGNORE INTO {$this->prefix}product_attribute_shop
            (id_product, id_product_attribute, id_shop, wholesale_price, price, ecotax, weight,
             unit_price_impact, default_on, minimal_quantity, available_date)
            SELECT
                pa.id_product,
                pas_source.id_product_attribute,
                s.id_shop,
                pas_source.wholesale_price,
                pas_source.price,
                pas_source.ecotax,
                pas_source.weight,
                pas_source.unit_price_impact,
                pas_source.default_on,
                pas_source.minimal_quantity,
                pas_source.available_date
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            INNER JOIN {$this->prefix}product_attribute_shop pas_source ON (
                pas_source.id_product_attribute = pa.id_product_attribute
                AND pas_source.id_shop = (
                    SELECT MIN(pas2.id_shop)
                    FROM {$this->prefix}product_attribute_shop pas2
                    WHERE pas2.id_product_attribute = pa.id_product_attribute
                )
            )
            WHERE p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_attribute_shop pas_existing
                WHERE pas_existing.id_product_attribute = pa.id_product_attribute
                AND pas_existing.id_shop = s.id_shop
            )",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $part2 = (int) $this->connection->executeStatement(
            "INSERT IGNORE INTO {$this->prefix}product_attribute_shop
            (id_product, id_product_attribute, id_shop, wholesale_price, price, ecotax, weight,
             unit_price_impact, default_on, minimal_quantity, available_date)
            SELECT
                pa.id_product,
                pa.id_product_attribute,
                s.id_shop,
                0,
                0,
                0,
                0,
                0,
                NULL,
                1,
                '0000-00-00'
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            WHERE p.active = 1
            AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_attribute_shop pas_existing
                WHERE pas_existing.id_product_attribute = pa.id_product_attribute
                AND pas_existing.id_shop = s.id_shop
            )",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $part3 = (int) $this->connection->executeStatement(
            "UPDATE {$this->prefix}product_attribute_shop pas_fix
            INNER JOIN (
                SELECT MIN(pas.id_product_attribute) as first_attr, pa.id_product, pas.id_shop
                FROM {$this->prefix}product_attribute pa
                INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
                INNER JOIN {$this->prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute
                WHERE p.active = 1 AND p.id_product NOT IN (?)
                GROUP BY pa.id_product, pas.id_shop
                HAVING SUM(CASE WHEN pas.default_on = 1 THEN 1 ELSE 0 END) = 0
            ) needs_fix ON pas_fix.id_product_attribute = needs_fix.first_attr AND pas_fix.id_shop = needs_fix.id_shop
            SET pas_fix.default_on = 1",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        return $part1 + $part2 + $part3;
    }

    private function fixAttributeShop(array $shops, int $sourceShopId): int
    {
        $totalInserted = 0;

        foreach ($shops as $targetShopId) {
            if ($targetShopId == $sourceShopId) {
                continue;
            }

            $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}attribute_shop
                (id_attribute, id_shop)
                SELECT a_shop.id_attribute, ? as id_shop
                FROM {$this->prefix}attribute_shop a_shop
                WHERE a_shop.id_shop = ?
            ", [$targetShopId, $sourceShopId]);

            $totalInserted += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
        }

        return $totalInserted;
    }

    private function fixCategoryLang(array $shops, int $sourceShopId): int
    {
        $totalInserted = 0;

        foreach ($shops as $targetShopId) {
            if ($targetShopId == $sourceShopId) {
                continue;
            }

            $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}category_lang
                (id_category, id_lang, id_shop, description, link_rewrite, meta_description,
                 meta_keywords, meta_title, name)
                SELECT
                    cl.id_category,
                    cl.id_lang,
                    ? as id_shop,
                    cl.description,
                    cl.link_rewrite,
                    cl.meta_description,
                    cl.meta_keywords,
                    cl.meta_title,
                    cl.name
                FROM {$this->prefix}category_lang cl
                INNER JOIN {$this->prefix}category c ON cl.id_category = c.id_category
                WHERE cl.id_shop = ? AND c.active = 1
            ", [$targetShopId, $sourceShopId]);

            $totalInserted += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
        }

        return $totalInserted;
    }

    private function fixSpecificPrice(array $shops, int $sourceShopId): int
    {
        $converted = (int) $this->connection->executeStatement("
            UPDATE {$this->prefix}specific_price sp1
            SET sp1.id_shop = 0
            WHERE sp1.id_shop > 0
            AND NOT EXISTS (
                SELECT 1 FROM (
                    SELECT id_product, id_product_attribute, id_currency, id_country,
                           id_group, id_customer, from_quantity
                    FROM {$this->prefix}specific_price
                    WHERE id_shop = 0
                ) sp2
                WHERE sp2.id_product = sp1.id_product
                AND sp2.id_product_attribute = sp1.id_product_attribute
                AND sp2.id_currency = sp1.id_currency
                AND sp2.id_country = sp1.id_country
                AND sp2.id_group = sp1.id_group
                AND sp2.id_customer = sp1.id_customer
                AND sp2.from_quantity = sp1.from_quantity
            )
        ");

        $cleaned = (int) $this->connection->executeStatement("
            DELETE sp1 FROM {$this->prefix}specific_price sp1
            INNER JOIN {$this->prefix}specific_price sp2
                ON sp2.id_product = sp1.id_product
                AND sp2.id_product_attribute = sp1.id_product_attribute
                AND sp2.id_currency = sp1.id_currency
                AND sp2.id_country = sp1.id_country
                AND sp2.id_group = sp1.id_group
                AND sp2.id_customer = sp1.id_customer
                AND sp2.from_quantity = sp1.from_quantity
                AND sp2.id_shop = 0
            WHERE sp1.id_shop > 0
        ");

        return $converted + $cleaned;
    }

    private function fixFeatureProduct(array $shops): int
    {
        $sourceLang = (int) $this->connection->fetchOne(
            "SELECT id_lang FROM {$this->prefix}lang WHERE active = 1 ORDER BY id_lang LIMIT 1"
        );

        $this->connection->executeStatement("
            INSERT IGNORE INTO {$this->prefix}feature_value_lang
            (id_feature_value, id_lang, value)
            SELECT fvl.id_feature_value, l.id_lang, fvl.value
            FROM {$this->prefix}feature_value_lang fvl
            CROSS JOIN {$this->prefix}lang l
            WHERE l.active = 1
            AND fvl.id_lang = ?
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}feature_value_lang fvl2
                WHERE fvl2.id_feature_value = fvl.id_feature_value
                AND fvl2.id_lang = l.id_lang
            )
        ", [$sourceLang]);

        return (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
    }

    private function fixFeatureShop(array $shops, int $sourceShopId): int
    {
        $totalInserted = 0;

        foreach ($shops as $targetShopId) {
            if ($targetShopId == $sourceShopId) {
                continue;
            }

            $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}feature_shop
                (id_feature, id_shop)
                SELECT fs.id_feature, ? as id_shop
                FROM {$this->prefix}feature_shop fs
                WHERE fs.id_shop = ?
            ", [$targetShopId, $sourceShopId]);

            $totalInserted += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
        }

        return $totalInserted;
    }

    private function fixProductCarrier(array $shops): int
    {
        $totalInserted = 0;
        $sourceShopId = $shops[0] ?? 1;

        $productCarriers = $this->connection->fetchAllAssociative("
            SELECT DISTINCT pc.id_product, pc.id_carrier_reference
            FROM {$this->prefix}product_carrier pc
            WHERE pc.id_shop = ?
        ", [$sourceShopId]);

        foreach ($productCarriers as $pc) {
            foreach ($shops as $targetShopId) {
                if ($targetShopId == $sourceShopId) {
                    continue;
                }

                $exists = $this->connection->fetchOne("
                    SELECT 1 FROM {$this->prefix}product_carrier
                    WHERE id_product = ? AND id_carrier_reference = ? AND id_shop = ?
                ", [$pc['id_product'], $pc['id_carrier_reference'], $targetShopId]);

                if (!$exists) {
                    $this->connection->insert("{$this->prefix}product_carrier", [
                        'id_product' => $pc['id_product'],
                        'id_carrier_reference' => $pc['id_carrier_reference'],
                        'id_shop' => $targetShopId,
                    ]);
                    ++$totalInserted;
                }
            }
        }

        return $totalInserted;
    }

    private function fixCategoryShop(array $shops): int
    {
        $totalInserted = 0;
        $sourceShopId = $shops[0] ?? 1;

        foreach ($shops as $targetShopId) {
            if ($targetShopId == $sourceShopId) {
                continue;
            }

            $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}category_shop
                (id_category, id_shop, position)
                SELECT cs.id_category, ? as id_shop, cs.position
                FROM {$this->prefix}category_shop cs
                INNER JOIN {$this->prefix}category c ON cs.id_category = c.id_category
                WHERE cs.id_shop = ? AND c.active = 1
            ", [$targetShopId, $sourceShopId]);

            $totalInserted += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
        }

        return $totalInserted;
    }
}
