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
 * Fixer for carrier and payment module multishop associations
 */
class CarrierFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['carriers_payments'];
    }

    public function preview(string $type): array
    {
        return match ($type) {
            'carriers_payments' => $this->previewCarriersPayments(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type): array
    {
        return match ($type) {
            'carriers_payments' => $this->fixCarriersPayments(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    private function previewCarriersPayments(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            "SELECT id_shop FROM {$this->prefix}shop WHERE active = 1 ORDER BY id_shop"
        );

        $carriers = $this->connection->fetchAllAssociative("
            SELECT id_carrier, name
            FROM {$this->prefix}carrier
            WHERE deleted = 0
        ");

        $modules = $this->connection->fetchAllAssociative("
            SELECT id_module, name
            FROM {$this->prefix}module
            WHERE name IN ('paypal', 'stripe_official')
        ");

        $currentDelivery = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}delivery"
        );

        $totalShops = count($shops);
        $missingCarrierShop = 0;
        foreach ($carriers as $carrier) {
            $shopCount = (int) $this->connection->fetchOne("
                SELECT COUNT(*) FROM {$this->prefix}carrier_shop
                WHERE id_carrier = ?
            ", [$carrier['id_carrier']]);
            if ($shopCount < $totalShops) {
                $missingCarrierShop += ($totalShops - $shopCount);
            }
        }

        return [
            'success' => true,
            'type' => 'carriers_payments',
            'description' => 'Associations multishop transporteurs + duplication tarifs + modules paiement',
            'changes' => [
                'carriers_to_associate' => count($carriers),
                'missing_carrier_shop' => $missingCarrierShop,
                'current_delivery_entries' => $currentDelivery,
                'shops' => $totalShops,
                'payment_modules' => count($modules),
            ],
        ];
    }

    /**
     * Fix carriers and payments - Multishop associations
     *
     * Optimized: carrier_shop uses INSERT IGNORE...SELECT with CROSS JOIN instead of N+1 loops
     */
    private function fixCarriersPayments(): array
    {
        try {
            $results = [
                'success' => true,
                'type' => 'carriers_payments',
            ];

            $shops = $this->connection->fetchFirstColumn(
                "SELECT id_shop FROM {$this->prefix}shop WHERE active = 1 ORDER BY id_shop"
            );

            // Step 1: Fix carrier_shop - batch INSERT IGNORE with CROSS JOIN (was N+1)
            $carrierShopInserts = (int) $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}carrier_shop (id_carrier, id_shop)
                SELECT c.id_carrier, s.id_shop
                FROM {$this->prefix}carrier c
                CROSS JOIN {$this->prefix}shop s
                WHERE c.deleted = 0 AND s.active = 1
            ");
            $results['carrier_shop_created'] = $carrierShopInserts;

            // Step 2: Duplicate delivery for shops that don't have entries
            $deliveryDuplicated = 0;
            foreach ($shops as $shopId) {
                if ($shopId == 1) {
                    continue;
                }

                $existingCount = (int) $this->connection->fetchOne("
                    SELECT COUNT(*) FROM {$this->prefix}delivery WHERE id_shop = ?
                ", [$shopId]);

                if ($existingCount === 0) {
                    $this->connection->executeStatement("
                        INSERT INTO {$this->prefix}delivery
                        (id_shop, id_shop_group, id_carrier, id_range_price, id_range_weight, id_zone, price)
                        SELECT
                            ?,
                            id_shop_group,
                            id_carrier,
                            id_range_price,
                            id_range_weight,
                            id_zone,
                            price
                        FROM {$this->prefix}delivery
                        WHERE id_shop = 1 OR id_shop IS NULL
                    ", [$shopId]);
                    $deliveryDuplicated += (int) $this->connection->fetchOne('SELECT ROW_COUNT()');
                }
            }
            $results['delivery_duplicated'] = $deliveryDuplicated;

            // Step 3: Fix module_carrier for PayPal/Stripe
            $references = [1, 2, 12, 20, 21, 22, 23, 40, 63, 71, 73, 77, 82, 87, 95, 101, 102];

            $modules = $this->connection->fetchAllAssociative("
                SELECT id_module, name FROM {$this->prefix}module
                WHERE name IN ('paypal', 'stripe_official')
            ");

            $moduleIds = array_column($modules, 'id_module');
            if (!empty($moduleIds)) {
                $this->connection->executeStatement(
                    "DELETE FROM {$this->prefix}module_carrier WHERE id_module IN (?)",
                    [$moduleIds],
                    [Connection::PARAM_INT_ARRAY]
                );
            }

            $moduleCarrierInserts = 0;
            foreach ($modules as $module) {
                foreach ($shops as $shopId) {
                    foreach ($references as $refId) {
                        $this->connection->insert("{$this->prefix}module_carrier", [
                            'id_module' => $module['id_module'],
                            'id_shop' => $shopId,
                            'id_reference' => $refId,
                        ]);
                        ++$moduleCarrierInserts;
                    }
                }
            }
            $results['module_carrier_created'] = $moduleCarrierInserts;

            return $results;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
