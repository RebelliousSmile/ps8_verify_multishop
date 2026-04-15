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

class PriceAnomalyCheck implements CheckInterface
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
        return 'product_shop — prix à 0€ (anomalie)';
    }

    public function run(): CheckResult
    {
        $excludedClause = MultishopConstants::hasExcludedProducts()
            ? 'AND p.id_product NOT IN (' . implode(',', MultishopConstants::EXCLUDED_PRODUCTS) . ')'
            : '';

        $sqlDetail = sprintf(
            'SELECT ps.id_product, ps.id_shop, ps.price AS shop_price, p.price AS base_price
             FROM `%sproduct_shop` ps
             JOIN `%sproduct` p ON p.id_product = ps.id_product AND p.active = 1
             WHERE ps.price = 0 AND p.price > 0
               %s
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $excludedClause
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        if (empty($rows)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Aucun produit avec prix à 0€ dans product_shop alors que le prix de base est > 0.',
                check_query: "SELECT ps.id_product, ps.id_shop, ps.price AS shop_price, p.price AS base_price\nFROM {prefix}product_shop ps\nJOIN {prefix}product p ON p.id_product = ps.id_product AND p.active = 1\nWHERE ps.price = 0 AND p.price > 0;",
            );
        }

        $issues = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];
            $basePrice = (float) $row['base_price'];

            $issues[] = [
                'detail' => sprintf(
                    'Produit %d — prix 0€ sur boutique %d alors que prix de base = %s€ → vérifier manuellement',
                    $idProduct,
                    $idShop,
                    number_format($basePrice, 2, '.', '')
                ),
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'warning',
            message: sprintf('%d produit(s) avec prix à 0€ dans product_shop (max 50 affichés)', count($rows)),
            issues: $issues,
            check_query: "SELECT ps.id_product, ps.id_shop, ps.price AS shop_price, p.price AS base_price\nFROM {prefix}product_shop ps\nJOIN {prefix}product p ON p.id_product = ps.id_product AND p.active = 1\nWHERE ps.price = 0 AND p.price > 0;",
        );
    }
}
