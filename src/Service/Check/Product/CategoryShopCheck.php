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

class CategoryShopCheck implements CheckInterface
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
        return 'category_shop — catégories par boutique';
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
        $sqlCount = sprintf(
            'SELECT COUNT(*) AS c
             FROM `%scategory` c
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%scategory_shop` cs
               ON cs.id_category = c.id_category AND cs.id_shop = s.id_shop
             WHERE c.active = 1 AND cs.id_category IS NULL',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix
        );

        $countRow = $this->connection->fetchAssociative($sqlCount);
        $count = $countRow ? (int) $countRow['c'] : 0;

        if ($count === 0) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Toutes les catégories sont associées à chaque boutique dans category_shop.',
                check_query: "SELECT COUNT(*) FROM {prefix}category c\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}category_shop cs ON cs.id_category = c.id_category AND cs.id_shop = s.id_shop\nWHERE c.active = 1 AND cs.id_category IS NULL;",
            );
        }

        $sourceShop = $this->getSourceShop();

        $sqlDetail = sprintf(
            'SELECT c.id_category, s.id_shop
             FROM `%scategory` c
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%scategory_shop` cs
               ON cs.id_category = c.id_category AND cs.id_shop = s.id_shop
             WHERE c.active = 1 AND cs.id_category IS NULL
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        $issues = [];
        foreach ($rows as $row) {
            $idCategory = (int) $row['id_category'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%scategory_shop` (id_category, id_shop, position)'
                . ' SELECT id_category, %d, position FROM `%scategory_shop`'
                . ' WHERE id_category = %d AND id_shop = %d;',
                $this->dbPrefix,
                $idShop,
                $this->dbPrefix,
                $idCategory,
                $sourceShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Catégorie %d — association manquante dans category_shop pour boutique %d',
                    $idCategory,
                    $idShop
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d lignes manquantes dans category_shop (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}category c\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}category_shop cs ON cs.id_category = c.id_category AND cs.id_shop = s.id_shop\nWHERE c.active = 1 AND cs.id_category IS NULL;",
        );
    }
}
