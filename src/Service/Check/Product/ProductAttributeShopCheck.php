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

class ProductAttributeShopCheck implements CheckInterface
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
        return 'product_attribute_shop — combinaisons par boutique';
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
             FROM `%sproduct_attribute` pa
             JOIN `%sproduct` p ON p.id_product = pa.id_product AND p.active = 1
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sproduct_attribute_shop` pas
               ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop
             WHERE pas.id_product_attribute IS NULL
               %s',
            $this->dbPrefix,
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
                message: 'Toutes les combinaisons sont présentes dans product_attribute_shop pour chaque boutique.',
                check_query: "SELECT COUNT(*) FROM {prefix}product_attribute pa\nJOIN {prefix}product p ON p.id_product = pa.id_product AND p.active = 1\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop\nWHERE pas.id_product_attribute IS NULL;",
            );
        }

        $sourceShop = $this->getSourceShop();

        $sqlDetail = sprintf(
            'SELECT pa.id_product_attribute, pa.id_product, s.id_shop
             FROM `%sproduct_attribute` pa
             JOIN `%sproduct` p ON p.id_product = pa.id_product AND p.active = 1
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sproduct_attribute_shop` pas
               ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop
             WHERE pas.id_product_attribute IS NULL
               %s
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $excludedClause
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        $issues = [];
        foreach ($rows as $row) {
            $idProductAttribute = (int) $row['id_product_attribute'];
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%sproduct_attribute_shop`'
                . ' (id_product_attribute, id_shop, price, ecotax, weight, unit_price_impact,'
                . ' default_on, minimal_quantity, available_date)'
                . ' SELECT id_product_attribute, %d, price, ecotax, weight, unit_price_impact,'
                . ' default_on, minimal_quantity, available_date'
                . ' FROM `%sproduct_attribute_shop`'
                . ' WHERE id_product_attribute = %d AND id_shop = %d;',
                $this->dbPrefix,
                $idShop,
                $this->dbPrefix,
                $idProductAttribute,
                $sourceShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Combinaison %d (produit %d) — manquante dans product_attribute_shop pour boutique %d',
                    $idProductAttribute,
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
            message: sprintf('%d lignes manquantes dans product_attribute_shop (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}product_attribute pa\nJOIN {prefix}product p ON p.id_product = pa.id_product AND p.active = 1\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop\nWHERE pas.id_product_attribute IS NULL;",
        );
    }
}
