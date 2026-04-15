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

class AttributeShopCheck implements CheckInterface
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
        return 'attribute_shop — attributs par boutique';
    }

    public function run(): CheckResult
    {
        $sqlDetail = sprintf(
            'SELECT a.id_attribute, s.id_shop
             FROM `%sattribute` a
             CROSS JOIN `%sshop` s ON s.active = 1
             LEFT JOIN `%sattribute_shop` ash
               ON ash.id_attribute = a.id_attribute AND ash.id_shop = s.id_shop
             WHERE ash.id_attribute IS NULL
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
                message: 'Tous les attributs sont associés à chaque boutique dans attribute_shop.',
                check_query: "SELECT a.id_attribute, s.id_shop FROM {prefix}attribute a\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}attribute_shop ash ON ash.id_attribute = a.id_attribute AND ash.id_shop = s.id_shop\nWHERE ash.id_attribute IS NULL;",
            );
        }

        $issues = [];
        foreach ($rows as $row) {
            $idAttribute = (int) $row['id_attribute'];
            $idShop = (int) $row['id_shop'];

            $fixQuery = sprintf(
                'INSERT IGNORE INTO `%sattribute_shop` (id_attribute, id_shop) VALUES (%d, %d);',
                $this->dbPrefix,
                $idAttribute,
                $idShop
            );

            $issues[] = [
                'detail' => sprintf(
                    'Attribut %d — manquant dans attribute_shop pour boutique %d',
                    $idAttribute,
                    $idShop
                ),
                'fix_query' => $fixQuery,
            ];
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf('%d attribut(s) manquants dans attribute_shop (max 50 affichés)', count($rows)),
            issues: $issues,
            check_query: "SELECT a.id_attribute, s.id_shop FROM {prefix}attribute a\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}attribute_shop ash ON ash.id_attribute = a.id_attribute AND ash.id_shop = s.id_shop\nWHERE ash.id_attribute IS NULL;",
        );
    }
}
