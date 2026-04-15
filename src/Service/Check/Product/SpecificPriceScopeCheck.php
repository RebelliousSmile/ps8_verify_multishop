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

class SpecificPriceScopeCheck implements CheckInterface
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
        return 'specific_price — promotions hors scope global';
    }

    public function run(): CheckResult
    {
        $sqlDetail = sprintf(
            'SELECT id_specific_price, id_product, id_shop, price, reduction
             FROM `%sspecific_price`
             WHERE id_shop > 0
             LIMIT 50',
            $this->dbPrefix
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        if (empty($rows)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Toutes les promotions sont en scope global (id_shop = 0).',
                check_query: "SELECT id_specific_price, id_product, id_shop, price, reduction\nFROM {prefix}specific_price\nWHERE id_shop > 0;",
            );
        }

        $issues = [];
        foreach ($rows as $row) {
            $idSpecificPrice = (int) $row['id_specific_price'];
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];

            $step1 = sprintf(
                'UPDATE `%sspecific_price` SET id_shop = 0'
                . ' WHERE id_specific_price = %d'
                . ' AND NOT EXISTS ('
                . '   SELECT 1 FROM `%sspecific_price` s2'
                . '   WHERE s2.id_product = %d AND s2.id_shop = 0'
                . ');',
                $this->dbPrefix,
                $idSpecificPrice,
                $this->dbPrefix,
                $idProduct
            );

            $step2 = sprintf(
                'DELETE FROM `%sspecific_price`'
                . ' WHERE id_specific_price = %d'
                . ' AND id_shop > 0'
                . ' AND EXISTS ('
                . '   SELECT 1 FROM `%sspecific_price` s2'
                . '   WHERE s2.id_product = %d AND s2.id_shop = 0'
                . ');',
                $this->dbPrefix,
                $idSpecificPrice,
                $this->dbPrefix,
                $idProduct
            );

            $issues[] = [
                'detail' => sprintf(
                    'Prix promotionnel id=%d sur boutique %d, produit %d — doit être global',
                    $idSpecificPrice,
                    $idShop,
                    $idProduct
                ),
                'fix_query' => '-- Étape 1 : promouvoir en global si pas d\'équivalent' . "\n" . $step1,
                'fix_query_2' => '-- Étape 2 : supprimer le doublon shop-scopé si global existe' . "\n" . $step2,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'warning',
            message: sprintf('%d promotion(s) en scope boutique détectée(s) — vérifier si intentionnel (max 50 affichées)', count($rows)),
            issues: $issues,
            check_query: "SELECT id_specific_price, id_product, id_shop, price, reduction\nFROM {prefix}specific_price\nWHERE id_shop > 0;",
        );
    }
}
