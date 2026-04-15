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

class CategoryLangCheck implements CheckInterface
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
        return 'category_lang — traductions par boutique';
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
             CROSS JOIN `%slang` l ON l.active = 1
             LEFT JOIN `%scategory_lang` cl
               ON cl.id_category = c.id_category AND cl.id_shop = s.id_shop AND cl.id_lang = l.id_lang
             WHERE c.active = 1 AND cl.id_category IS NULL',
            $this->dbPrefix,
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
                message: 'Toutes les traductions category_lang sont présentes pour chaque boutique.',
                check_query: "SELECT COUNT(*) FROM {prefix}category c\nCROSS JOIN {prefix}shop s ON s.active = 1\nCROSS JOIN {prefix}lang l ON l.active = 1\nLEFT JOIN {prefix}category_lang cl ON cl.id_category = c.id_category AND cl.id_shop = s.id_shop AND cl.id_lang = l.id_lang\nWHERE c.active = 1 AND cl.id_category IS NULL;",
            );
        }

        $sourceShop = $this->getSourceShop();

        $sqlDetail = sprintf(
            'SELECT c.id_category, s.id_shop, l.id_lang
             FROM `%scategory` c
             CROSS JOIN `%sshop` s ON s.active = 1
             CROSS JOIN `%slang` l ON l.active = 1
             LEFT JOIN `%scategory_lang` cl
               ON cl.id_category = c.id_category AND cl.id_shop = s.id_shop AND cl.id_lang = l.id_lang
             WHERE c.active = 1 AND cl.id_category IS NULL
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        $issues = [];
        foreach ($rows as $row) {
            $idCategory = (int) $row['id_category'];
            $idShop = (int) $row['id_shop'];
            $idLang = (int) $row['id_lang'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%scategory_lang`'
                . ' (id_category, id_shop, id_lang, name, description, link_rewrite,'
                . ' meta_title, meta_keywords, meta_description, additional_description)'
                . ' SELECT id_category, %d, id_lang, name, description, link_rewrite,'
                . ' meta_title, meta_keywords, meta_description, additional_description'
                . ' FROM `%scategory_lang`'
                . ' WHERE id_category = %d AND id_shop = %d AND id_lang = %d;',
                $this->dbPrefix,
                $idShop,
                $this->dbPrefix,
                $idCategory,
                $sourceShop,
                $idLang
            );

            $issues[] = [
                'detail' => sprintf(
                    'Catégorie %d — traduction manquante pour boutique %d / langue %d',
                    $idCategory,
                    $idShop,
                    $idLang
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d lignes manquantes dans category_lang (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}category c\nCROSS JOIN {prefix}shop s ON s.active = 1\nCROSS JOIN {prefix}lang l ON l.active = 1\nLEFT JOIN {prefix}category_lang cl ON cl.id_category = c.id_category AND cl.id_shop = s.id_shop AND cl.id_lang = l.id_lang\nWHERE c.active = 1 AND cl.id_category IS NULL;",
        );
    }
}
