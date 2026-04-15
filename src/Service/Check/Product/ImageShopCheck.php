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

class ImageShopCheck implements CheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getDomain(): string
    {
        return 'images';
    }

    public function getLabel(): string
    {
        return 'image_shop — images par boutique';
    }

    public function run(): CheckResult
    {
        $sqlCount = sprintf(
            'SELECT COUNT(*) AS c
             FROM `%simage` i
             JOIN `%sproduct` p ON p.id_product = i.id_product AND p.active = 1
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%simage_shop` ish
               ON ish.id_image = i.id_image AND ish.id_shop = s.id_shop
             WHERE ish.id_image IS NULL',
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
                message: 'Toutes les images sont associées à chaque boutique dans image_shop.',
                check_query: "SELECT COUNT(*) FROM {prefix}image i\nJOIN {prefix}product p ON p.id_product = i.id_product AND p.active = 1\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}image_shop ish ON ish.id_image = i.id_image AND ish.id_shop = s.id_shop\nWHERE ish.id_image IS NULL;",
            );
        }

        $sqlDetail = sprintf(
            'SELECT i.id_image, s.id_shop
             FROM `%simage` i
             JOIN `%sproduct` p ON p.id_product = i.id_product AND p.active = 1
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%simage_shop` ish
               ON ish.id_image = i.id_image AND ish.id_shop = s.id_shop
             WHERE ish.id_image IS NULL
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        $issues = [];
        foreach ($rows as $row) {
            $idImage = (int) $row['id_image'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%simage_shop` (id_image, id_shop, cover) VALUES (%d, %d, 0);',
                $this->dbPrefix,
                $idImage,
                $idShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Image %d — manquante dans image_shop pour boutique %d',
                    $idImage,
                    $idShop
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d lignes manquantes dans image_shop (max 50 affichées)', $count),
            issues: $issues,
            check_query: "SELECT COUNT(*) FROM {prefix}image i\nJOIN {prefix}product p ON p.id_product = i.id_product AND p.active = 1\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}image_shop ish ON ish.id_image = i.id_image AND ish.id_shop = s.id_shop\nWHERE ish.id_image IS NULL;",
        );
    }
}
