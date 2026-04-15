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

class ProductLangCheck implements CheckInterface
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
        return 'product_lang — traductions par boutique';
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
             CROSS JOIN `%slang` l ON l.active = 1
             LEFT JOIN `%sproduct_lang` pl
               ON pl.id_product = p.id_product AND pl.id_shop = s.id_shop AND pl.id_lang = l.id_lang
             WHERE p.active = 1 AND pl.id_product IS NULL
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
                message: 'Toutes les traductions product_lang sont présentes pour chaque boutique.',
                check_query: "SELECT COUNT(*) FROM {prefix}product p\nCROSS JOIN {prefix}shop s ON s.active = 1\nCROSS JOIN {prefix}lang l ON l.active = 1\nLEFT JOIN {prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = s.id_shop AND pl.id_lang = l.id_lang\nWHERE p.active = 1 AND pl.id_product IS NULL;",
            );
        }

        $sourceShop = $this->getSourceShop();

        $sqlDetail = sprintf(
            'SELECT p.id_product, s.id_shop, l.id_lang
             FROM `%sproduct` p
             CROSS JOIN `%sshop` s ON s.active = 1
             CROSS JOIN `%slang` l ON l.active = 1
             LEFT JOIN `%sproduct_lang` pl
               ON pl.id_product = p.id_product AND pl.id_shop = s.id_shop AND pl.id_lang = l.id_lang
             WHERE p.active = 1 AND pl.id_product IS NULL
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
            $idProduct = (int) $row['id_product'];
            $idShop = (int) $row['id_shop'];
            $idLang = (int) $row['id_lang'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%sproduct_lang`'
                . ' (id_product, id_lang, id_shop, description, description_short, link_rewrite,'
                . ' meta_description, meta_keywords, meta_title, name, available_now, available_later,'
                . ' delivery_in_stock, delivery_out_stock)'
                . ' SELECT %d, id_lang, %d, description, description_short, link_rewrite,'
                . ' meta_description, meta_keywords, meta_title, name, available_now, available_later,'
                . ' delivery_in_stock, delivery_out_stock'
                . ' FROM `%sproduct_lang`'
                . ' WHERE id_product = %d AND id_shop = %d AND id_lang = %d;',
                $this->dbPrefix,
                $idProduct,
                $idShop,
                $this->dbPrefix,
                $idProduct,
                $sourceShop,
                $idLang
            );

            $issues[] = [
                'detail' => sprintf(
                    'Produit %d — traduction manquante pour boutique %d / langue %d',
                    $idProduct,
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
            message: sprintf('%d lignes manquantes dans product_lang (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}product p\nCROSS JOIN {prefix}shop s ON s.active = 1\nCROSS JOIN {prefix}lang l ON l.active = 1\nLEFT JOIN {prefix}product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = s.id_shop AND pl.id_lang = l.id_lang\nWHERE p.active = 1 AND pl.id_product IS NULL;",
        );
    }
}
