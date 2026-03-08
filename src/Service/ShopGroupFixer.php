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
 * Fixer for shop group sharing configuration
 */
class ShopGroupFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['shop_group_sharing'];
    }

    public function preview(string $type): array
    {
        return match ($type) {
            'shop_group_sharing' => $this->previewShopGroupSharing(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type): array
    {
        return match ($type) {
            'shop_group_sharing' => $this->fixShopGroupSharing(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    private function previewShopGroupSharing(): array
    {
        $shopGroups = $this->connection->fetchAllAssociative("
            SELECT id_shop_group, name, share_customer, share_order, share_stock
            FROM {$this->prefix}shop_group
        ");

        $groupsToFix = 0;
        $details = [];

        foreach ($shopGroups as $group) {
            $needsFix = false;
            $changes = [];

            if (!(bool) $group['share_customer']) {
                $needsFix = true;
                $changes[] = 'share_customer: 0 → 1';
            }
            if (!(bool) $group['share_order']) {
                $needsFix = true;
                $changes[] = 'share_order: 0 → 1';
            }
            if (!(bool) $group['share_stock']) {
                $needsFix = true;
                $changes[] = 'share_stock: 0 → 1';
            }

            if ($needsFix) {
                ++$groupsToFix;
                $details[] = [
                    'id' => $group['id_shop_group'],
                    'name' => $group['name'],
                    'changes' => $changes,
                ];
            }
        }

        return [
            'success' => true,
            'type' => 'shop_group_sharing',
            'description' => 'Activation du partage (customers, orders, stock) pour tous les shop groups - PRÉ-REQUIS CRITIQUE',
            'changes' => [
                'groups_to_fix' => $groupsToFix,
                'total_groups' => count($shopGroups),
            ],
            'details' => $details,
            'warning' => 'ATTENTION: Cette action est irréversible et doit être effectuée AVANT toutes les autres corrections!',
        ];
    }

    private function fixShopGroupSharing(): array
    {
        try {
            $affected = $this->connection->executeStatement("
                UPDATE {$this->prefix}shop_group
                SET share_customer = 1,
                    share_order = 1,
                    share_stock = 1
            ");

            $verification = $this->connection->fetchAllAssociative("
                SELECT id_shop_group, name, share_customer, share_order, share_stock
                FROM {$this->prefix}shop_group
            ");

            $allEnabled = true;
            foreach ($verification as $group) {
                if (!(bool) $group['share_customer'] || !(bool) $group['share_order'] || !(bool) $group['share_stock']) {
                    $allEnabled = false;
                    break;
                }
            }

            return [
                'success' => $allEnabled,
                'type' => 'shop_group_sharing',
                'rows_affected' => $affected,
                'all_shares_enabled' => $allEnabled,
                'shop_groups' => $verification,
                'message' => $allEnabled
                    ? 'Partage activé pour tous les shop groups. Vous pouvez maintenant exécuter les autres corrections.'
                    : 'Certains paramètres de partage n\'ont pas pu être activés.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
