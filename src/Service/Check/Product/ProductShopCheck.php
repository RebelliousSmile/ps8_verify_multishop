<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Service\Check\Product;

use Doctrine\DBAL\Connection;
use ScVerifyMultishop\Service\Check\CheckInterface;
use ScVerifyMultishop\Service\Check\CheckResult;
use ScVerifyMultishop\Service\MultishopConstants;

class ProductShopCheck implements CheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getDomain(): string
    {
        return 'products';
    }

    public function getLabel(): string
    {
        return 'product_shop — association par boutique';
    }

    private function getSourceShop(): int
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id_shop, COUNT(*) as c FROM ' . $this->dbPrefix . 'product_shop GROUP BY id_shop ORDER BY c DESC LIMIT 1'
        );
        return $row ? (int) $row['id_shop'] : 1;
    }

    public function run(): CheckResult
    {
        $excludedClause = MultishopConstants::hasExcludedProducts()
            ? 'AND p.id_product NOT IN (' . implode(',', MultishopConstants::EXCLUDED_PRODUCTS) . ')'
            : '';

        $sqlCount = sprintf(
            'SELECT COUNT(*) AS c
             FROM `%sproduct` p
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sproduct_shop` ps
               ON ps.id_product = p.id_product AND ps.id_shop = s.id_shop
             WHERE p.active = 1 AND ps.id_product IS NULL
               %s',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $excludedClause
        );

        $countRow = $this->connection->fetchAssociative($sqlCount);
        $count = $countRow ? (int) $countRow['c'] : 0;

        if ($count === 0) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Tous les produits sont associés à chaque boutique dans product_shop.',
                check_query: "SELECT COUNT(*) FROM {prefix}product p\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = s.id_shop\nWHERE p.active = 1 AND ps.id_product IS NULL;",
            );
        }

        $sourceShop = $this->getSourceShop();

        $sqlDetail = sprintf(
            'SELECT p.id_product, s.id_shop
             FROM `%sproduct` p
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sproduct_shop` ps
               ON ps.id_product = p.id_product AND ps.id_shop = s.id_shop
             WHERE p.active = 1 AND ps.id_product IS NULL
               %s
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $excludedClause
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        $issues = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%sproduct_shop`'
                . ' (id_product, id_shop, id_category_default, id_tax_rules_group, on_sale, online_only,'
                . ' ecotax, minimal_quantity, low_stock_threshold, low_stock_alert, price, wholesale_price,'
                . ' unity, unit_price_ratio, additional_shipping_cost, customizable, uploadable_files,'
                . ' text_fields, active, redirect_type, id_type_redirected, available_for_order,'
                . ' available_date, show_condition, `condition`, show_price, indexed, visibility,'
                . ' cache_default_attribute, advanced_stock_management, date_add, date_upd, pack_stock_type)'
                . ' SELECT id_product, %d, id_category_default, id_tax_rules_group, on_sale, online_only,'
                . ' ecotax, minimal_quantity, low_stock_threshold, low_stock_alert, price, wholesale_price,'
                . ' unity, unit_price_ratio, additional_shipping_cost, customizable, uploadable_files,'
                . ' text_fields, active, redirect_type, id_type_redirected, available_for_order,'
                . ' available_date, show_condition, `condition`, show_price, indexed, visibility,'
                . ' cache_default_attribute, advanced_stock_management, date_add, date_upd, pack_stock_type'
                . ' FROM `%sproduct_shop`'
                . ' WHERE id_product = %d AND id_shop = %d;',
                $this->dbPrefix,
                $idShop,
                $this->dbPrefix,
                $idProduct,
                $sourceShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Produit %d — association manquante dans product_shop pour boutique %d',
                    $idProduct,
                    $idShop
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d lignes manquantes dans product_shop (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}product p\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = s.id_shop\nWHERE p.active = 1 AND ps.id_product IS NULL;",
        );
    }
}
