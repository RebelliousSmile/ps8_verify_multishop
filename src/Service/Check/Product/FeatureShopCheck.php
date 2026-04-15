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

class FeatureShopCheck implements CheckInterface
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
        return 'feature_shop — caractéristiques par boutique';
    }

    public function run(): CheckResult
    {
        $sqlDetail = sprintf(
            'SELECT f.id_feature, s.id_shop
             FROM `%sfeature` f
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sfeature_shop` fs
               ON fs.id_feature = f.id_feature AND fs.id_shop = s.id_shop
             WHERE fs.id_feature IS NULL
             LIMIT 50',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix
        );

        $rows = $this->connection->fetchAllAssociative($sqlDetail);

        if (empty($rows)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Toutes les caractéristiques sont associées à chaque boutique dans feature_shop.',
                check_query: "SELECT f.id_feature, s.id_shop FROM {prefix}feature f\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}feature_shop fs ON fs.id_feature = f.id_feature AND fs.id_shop = s.id_shop\nWHERE fs.id_feature IS NULL;",
            );
        }

        $issues = [];
        foreach ($rows as $row) {
            $idFeature = (int) $row['id_feature'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%sfeature_shop` (id_feature, id_shop) VALUES (%d, %d);',
                $this->dbPrefix,
                $idFeature,
                $idShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Caractéristique %d — manquante dans feature_shop pour boutique %d',
                    $idFeature,
                    $idShop
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d caractéristique(s) manquante(s) dans feature_shop (max 50 affichées)', count($rows)),
            issues: $issues,
            check_query: "SELECT f.id_feature, s.id_shop FROM {prefix}feature f\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}feature_shop fs ON fs.id_feature = f.id_feature AND fs.id_shop = s.id_shop\nWHERE fs.id_feature IS NULL;",
        );
    }
}
