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

class DefaultAttributeShopCheck implements CheckInterface
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
        return 'product_attribute_shop — default_on manquant';
    }

    public function run(): CheckResult
    {
        $excludedClause = MultishopConstants::hasExcludedProducts()
            ? 'AND p.id_product NOT IN (' . implode(',', MultishopConstants::EXCLUDED_PRODUCTS) . ')'
            : '';

        $sqlDetail = sprintf(
            'SELECT p.id_product, s.id_shop
             FROM `%sproduct` p
             JOIN `%sproduct_attribute` pa ON pa.id_product = p.id_product
             CROSS JOIN `%sshop` s ON s.active = 1
             JOIN `%sproduct_attribute_shop` pas
               ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop
             WHERE p.active = 1 %s
             GROUP BY p.id_product, s.id_shop
             HAVING SUM(pas.default_on) = 0
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
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
                message: 'Toutes les combinaisons ont un default_on défini par boutique.',
                check_query: "SELECT p.id_product, s.id_shop FROM {prefix}product p\nJOIN {prefix}product_attribute pa ON pa.id_product = p.id_product\nCROSS JOIN {prefix}shop s ON s.active = 1\nJOIN {prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop\nWHERE p.active = 1\nGROUP BY p.id_product, s.id_shop\nHAVING SUM(pas.default_on) = 0;",
            );
        }

        $issues = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'UPDATE `%sproduct_attribute_shop` SET default_on = 1'
                . ' WHERE id_product_attribute = ('
                . '   SELECT pas_inner.id_product_attribute'
                . '   FROM `%sproduct_attribute_shop` pas_inner'
                . '   JOIN `%sproduct_attribute` pa_inner'
                . '     ON pa_inner.id_product_attribute = pas_inner.id_product_attribute'
                . '   WHERE pa_inner.id_product = %d AND pas_inner.id_shop = %d'
                . '   ORDER BY pas_inner.id_product_attribute DESC'
                . '   LIMIT 1'
                . ' ) AND id_shop = %d;',
                $this->dbPrefix,
                $this->dbPrefix,
                $this->dbPrefix,
                $idProduct,
                $idShop,
                $idShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Produit %d — aucune combinaison avec default_on = 1 pour boutique %d',
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
            message: sprintf('%d produit(s)/boutique(s) sans combinaison par défaut (max 50 affichés)', count($rows)),
            issues: $issues,
            check_query: "SELECT p.id_product, s.id_shop FROM {prefix}product p\nJOIN {prefix}product_attribute pa ON pa.id_product = p.id_product\nCROSS JOIN {prefix}shop s ON s.active = 1\nJOIN {prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop\nWHERE p.active = 1\nGROUP BY p.id_product, s.id_shop\nHAVING SUM(pas.default_on) = 0;",
        );
    }
}
