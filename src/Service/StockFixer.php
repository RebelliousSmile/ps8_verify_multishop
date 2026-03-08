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

class StockFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['stocks', 'duplicate_stocks', 'null_quantities', 'share_stock'];
    }

    public function preview(string $type, array $options = []): array
    {
        return match ($type) {
            'stocks' => $this->previewStocks(),
            'duplicate_stocks' => $this->previewDuplicateStocks(),
            'null_quantities' => $this->previewNullQuantities(),
            'share_stock' => $this->previewShareStock(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type, array $options = []): array
    {
        return match ($type) {
            'stocks' => $this->fixStocks(),
            'duplicate_stocks' => $this->fixDuplicateStocks(),
            'null_quantities' => $this->fixNullQuantities(),
            'share_stock' => $this->fixShareStock(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    private function previewStocks(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $shop1Count = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 1
        ");

        $wrongShopGroup = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop_group = 0
        ");

        return [
            'success' => true,
            'type' => 'stocks',
            'description' => 'Correction des stocks: id_shop→0, id_shop_group→1 (stock partagé)',
            'changes' => [
                'shop1_entries' => $shop1Count,
                'wrong_shop_group' => $wrongShopGroup,
                'total_shops' => $totalShops,
            ],
        ];
    }

    private function previewDuplicateStocks(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            "SELECT id_shop FROM {$this->prefix}shop WHERE active = 1 AND id_shop != 1"
        );

        $shop1Count = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 1
        ");

        return [
            'success' => true,
            'type' => 'duplicate_stocks',
            'description' => 'Duplication des stocks du shop 1 vers tous les autres shops',
            'changes' => [
                'shop1_entries' => $shop1Count,
                'target_shops' => count($shops),
                'estimated_inserts' => $shop1Count * count($shops),
            ],
        ];
    }

    private function previewNullQuantities(): array
    {
        $nullCount = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available
            WHERE id_shop = 0 AND quantity IS NULL
        ");

        $otherShopsStocks = $this->connection->fetchAllAssociative("
            SELECT id_shop, COUNT(*) as nb, COALESCE(SUM(quantity), 0) as total_qty
            FROM {$this->prefix}stock_available
            WHERE id_shop > 0 AND quantity IS NOT NULL
            GROUP BY id_shop
            ORDER BY id_shop
        ");

        $canRestore = count($otherShopsStocks) > 0;

        return [
            'success' => true,
            'type' => 'null_quantities',
            'description' => 'Correction intelligente des quantités NULL dans stock_available',
            'changes' => [
                'null_quantities_shop0' => $nullCount,
                'strategy' => $canRestore
                    ? 'Smart copy: restaurer depuis les autres shops, sinon mettre à 0'
                    : 'Aucun stock dans les autres shops - mise à 0 par défaut',
                'other_shops_available' => count($otherShopsStocks),
            ],
            'other_shops_data' => $otherShopsStocks,
        ];
    }

    private function previewShareStock(): array
    {
        $shareStockDisabled = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}shop_group WHERE share_stock = 0
        ");

        $nonGlobalStocks = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop > 0
        ");

        $shopGroupId = (int) $this->connection->fetchOne(
            "SELECT id_shop_group FROM {$this->prefix}shop_group ORDER BY id_shop_group LIMIT 1"
        );

        $wrongShopGroup = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop_group != ?
        ", [$shopGroupId]);

        return [
            'success' => true,
            'type' => 'share_stock',
            'description' => 'Configuration du partage de stock entre shops (share_stock + id_shop→0)',
            'changes' => [
                'shop_groups_to_fix' => $shareStockDisabled,
                'stocks_to_convert_to_global' => $nonGlobalStocks,
                'wrong_shop_group' => $wrongShopGroup,
            ],
        ];
    }

    private function fixStocks(): array
    {
        try {
            $countShop1 = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 1
            ");
            $countGlobal = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 0
            ");
            $wrongShopGroup = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop_group = 0
            ");

            $step0Deleted = 0;
            $step1Rows = 0;
            $step2Rows = 0;

            $this->connection->beginTransaction();
            try {
                if ($countShop1 > 0) {
                    if ($countGlobal > 0) {
                        $step0Deleted = $this->connection->executeStatement("
                            DELETE g FROM {$this->prefix}stock_available g
                            INNER JOIN {$this->prefix}stock_available s
                                ON g.id_product = s.id_product
                                AND g.id_product_attribute = s.id_product_attribute
                            WHERE g.id_shop = 0 AND s.id_shop = 1
                        ");

                        $this->connection->executeStatement("
                            DELETE FROM {$this->prefix}stock_available WHERE id_shop = 0
                        ");
                    }

                    $step1Rows = $this->connection->executeStatement("
                        UPDATE {$this->prefix}stock_available SET id_shop = 0 WHERE id_shop = 1
                    ");
                }

                if ($wrongShopGroup > 0) {
                    $step2Rows = $this->connection->executeStatement("
                        UPDATE {$this->prefix}stock_available SET id_shop_group = 1 WHERE id_shop_group = 0
                    ");
                }

                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();

                return [
                    'success' => false,
                    'type' => 'stocks',
                    'error' => $e->getMessage(),
                ];
            }

            $newCountGlobal = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 0
            ");
            $newCountShop1 = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available WHERE id_shop = 1
            ");

            return [
                'success' => true,
                'type' => 'stocks',
                'step0_cleaned' => $step0Deleted,
                'step1_converted' => $step1Rows,
                'step2_fixed' => $step2Rows,
                'global_entries' => $newCountGlobal,
                'shop1_remaining' => $newCountShop1,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'stocks',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fixDuplicateStocks(): array
    {
        try {
            $shops = $this->connection->fetchFirstColumn(
                "SELECT id_shop FROM {$this->prefix}shop WHERE active = 1 AND id_shop != 1"
            );

            $totalInserted = 0;
            foreach ($shops as $shopId) {
                $this->connection->executeStatement("
                    INSERT IGNORE INTO {$this->prefix}stock_available
                    (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock, location)
                    SELECT
                        id_product,
                        id_product_attribute,
                        ?,
                        id_shop_group,
                        quantity,
                        depends_on_stock,
                        out_of_stock,
                        location
                    FROM {$this->prefix}stock_available
                    WHERE id_shop = 1
                ", [$shopId]);

                $totalInserted += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
            }

            return [
                'success' => true,
                'type' => 'duplicate_stocks',
                'inserted' => $totalInserted,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fixNullQuantities(): array
    {
        try {
            $nbNull = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available
                WHERE id_shop = 0 AND quantity IS NULL
            ");

            if ($nbNull === 0) {
                return [
                    'success' => true,
                    'type' => 'null_quantities',
                    'message' => 'Aucune quantité NULL détectée',
                    'restored_from_shops' => 0,
                    'set_to_zero' => 0,
                ];
            }

            $otherShopsStocks = $this->connection->fetchAllAssociative("
                SELECT id_shop, COUNT(*) as nb, COALESCE(SUM(quantity), 0) as total_qty
                FROM {$this->prefix}stock_available
                WHERE id_shop > 0
                GROUP BY id_shop
                ORDER BY id_shop
            ");

            $restored = 0;
            $setToZero = 0;

            if (count($otherShopsStocks) > 0) {
                $nullEntries = $this->connection->fetchAllAssociative("
                    SELECT id_stock_available, id_product, id_product_attribute
                    FROM {$this->prefix}stock_available
                    WHERE id_shop = 0 AND quantity IS NULL
                ");

                foreach ($nullEntries as $entry) {
                    $sourceQty = $this->connection->fetchOne("
                        SELECT quantity
                        FROM {$this->prefix}stock_available
                        WHERE id_product = ?
                        AND id_product_attribute = ?
                        AND id_shop > 0
                        AND quantity IS NOT NULL
                        ORDER BY id_shop ASC
                        LIMIT 1
                    ", [$entry['id_product'], $entry['id_product_attribute']]);

                    if ($sourceQty !== false && $sourceQty !== null) {
                        $this->connection->executeStatement(
                            "UPDATE {$this->prefix}stock_available SET quantity = ? WHERE id_stock_available = ?",
                            [(int) $sourceQty, $entry['id_stock_available']]
                        );
                        ++$restored;
                    } else {
                        $this->connection->executeStatement(
                            "UPDATE {$this->prefix}stock_available SET quantity = 0 WHERE id_stock_available = ?",
                            [$entry['id_stock_available']]
                        );
                        ++$setToZero;
                    }
                }
            } else {
                $setToZero = $this->connection->executeStatement("
                    UPDATE {$this->prefix}stock_available SET quantity = 0 WHERE quantity IS NULL
                ");
            }

            $remainingNull = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}stock_available
                WHERE id_shop = 0 AND quantity IS NULL
            ");

            return [
                'success' => true,
                'type' => 'null_quantities',
                'initial_null' => $nbNull,
                'restored_from_shops' => $restored,
                'set_to_zero' => $setToZero,
                'remaining_null' => $remainingNull,
                'other_shops_data' => $otherShopsStocks,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'null_quantities',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fixShareStock(): array
    {
        try {
            $groupUpdated = (int) $this->connection->executeStatement("
                UPDATE {$this->prefix}shop_group SET share_stock = 1 WHERE share_stock = 0
            ");

            $shopGroupId = (int) $this->connection->fetchOne(
                "SELECT id_shop_group FROM {$this->prefix}shop_group ORDER BY id_shop_group LIMIT 1"
            );

            $stocksConverted = (int) $this->connection->executeStatement("
                UPDATE {$this->prefix}stock_available SET id_shop = 0 WHERE id_shop > 0
            ");

            $groupFixed = (int) $this->connection->executeStatement("
                UPDATE {$this->prefix}stock_available SET id_shop_group = ? WHERE id_shop_group != ?
            ", [$shopGroupId, $shopGroupId]);

            return [
                'success' => true,
                'type' => 'share_stock',
                'shop_group_updated' => $groupUpdated,
                'stocks_converted_to_global' => $stocksConverted,
                'shop_group_fixed' => $groupFixed,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'share_stock',
                'error' => $e->getMessage(),
            ];
        }
    }
}
