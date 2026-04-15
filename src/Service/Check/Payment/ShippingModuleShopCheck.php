<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Service\Check\Payment;

use Doctrine\DBAL\Connection;
use ScVerifyMultishop\Service\Check\CheckInterface;
use ScVerifyMultishop\Service\Check\CheckResult;

class ShippingModuleShopCheck implements CheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getDomain(): string
    {
        return 'shipping';
    }

    public function getLabel(): string
    {
        return 'Modules de livraison — association boutiques';
    }

    public function run(): CheckResult
    {
        $hookName = 'displayCarrierList';

        // Query 1: Get all modules linked to the displayCarrierList hook
        $sqlModules = sprintf(
            'SELECT DISTINCT m.id_module, m.name
             FROM `%smodule` m
             INNER JOIN `%shook_module` hm ON hm.id_module = m.id_module
             INNER JOIN `%shook` h ON h.id_hook = hm.id_hook
             WHERE h.name = \'%s\'
               AND m.active = 1',
            $this->dbPrefix,
            $this->dbPrefix,
            $this->dbPrefix,
            addslashes($hookName)
        );

        $modules = $this->connection->fetchAllAssociative($sqlModules);

        // Query 2: Get all active shops
        $sqlShops = sprintf(
            'SELECT id_shop, name FROM `%sshop` WHERE active = 1',
            $this->dbPrefix
        );

        $shops = $this->connection->fetchAllAssociative($sqlShops);

        if (empty($modules)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Aucun module de livraison détecté via le hook displayCarrierList.',
                check_query: "SELECT m.name, s.id_shop, s.name as shop_name\nFROM {prefix}module m\nJOIN {prefix}hook_module hm ON hm.id_module = m.id_module\nJOIN {prefix}hook h ON h.id_hook = hm.id_hook AND h.name = 'displayCarrierList'\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}module_shop ms ON ms.id_module = m.id_module AND ms.id_shop = s.id_shop\nWHERE ms.id_module IS NULL;",
            );
        }

        $issues = [];

        foreach ($modules as $module) {
            $moduleName = $module['name'];
            $idModule = (int) $module['id_module'];

            foreach ($shops as $shop) {
                $idShop = (int) $shop['id_shop'];
                $shopName = $shop['name'];

                // Check module_shop
                $sqlModuleShop = sprintf(
                    'SELECT COUNT(*) AS cnt
                     FROM `%smodule_shop`
                     WHERE id_module = %d AND id_shop = %d',
                    $this->dbPrefix,
                    $idModule,
                    $idShop
                );

                $rowModuleShop = $this->connection->fetchAllAssociative($sqlModuleShop);
                $moduleShopExists = isset($rowModuleShop[0]) && (int) $rowModuleShop[0]['cnt'] > 0;

                if (!$moduleShopExists) {
                    $fixQuery = sprintf(
                        'INSERT IGNORE INTO `%smodule_shop` (id_module, id_shop) SELECT id_module, %d FROM `%smodule` WHERE name = \'%s\' LIMIT 1;',
                        $this->dbPrefix,
                        $idShop,
                        $this->dbPrefix,
                        addslashes($moduleName)
                    );

                    $issues[] = [
                        'detail' => sprintf(
                            'Module "%s" absent de module_shop pour la boutique "%s" (id=%d)',
                            $moduleName,
                            $shopName,
                            $idShop
                        ),
                        'fix_query' => $fixQuery,
                    ];
                }

                // Check hook_module for displayCarrierList
                $sqlHookModule = sprintf(
                    'SELECT COUNT(*) AS cnt
                     FROM `%shook_module` hm
                     INNER JOIN `%shook` h ON h.id_hook = hm.id_hook
                     WHERE hm.id_module = %d
                       AND hm.id_shop = %d
                       AND h.name = \'%s\'',
                    $this->dbPrefix,
                    $this->dbPrefix,
                    $idModule,
                    $idShop,
                    addslashes($hookName)
                );

                $rowHookModule = $this->connection->fetchAllAssociative($sqlHookModule);
                $hookModuleExists = isset($rowHookModule[0]) && (int) $rowHookModule[0]['cnt'] > 0;

                if (!$hookModuleExists) {
                    $fixQuery = sprintf(
                        'INSERT IGNORE INTO `%shook_module` (id_module, id_hook, id_shop) SELECT m.id_module, h.id_hook, %d FROM `%smodule` m JOIN `%shook` h ON h.name = \'%s\' WHERE m.name = \'%s\' LIMIT 1;',
                        $this->dbPrefix,
                        $idShop,
                        $this->dbPrefix,
                        $this->dbPrefix,
                        addslashes($hookName),
                        addslashes($moduleName)
                    );

                    $issues[] = [
                        'detail' => sprintf(
                            'Module "%s" non enregistré sur le hook "%s" pour la boutique "%s" (id=%d)',
                            $moduleName,
                            $hookName,
                            $shopName,
                            $idShop
                        ),
                        'fix_query' => $fixQuery,
                    ];
                }
            }
        }

        $moduleCount = count($modules);

        if (empty($issues)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: sprintf('%d module(s) de livraison vérifiés — aucun problème détecté.', $moduleCount),
                check_query: "SELECT m.name, s.id_shop, s.name as shop_name\nFROM {prefix}module m\nJOIN {prefix}hook_module hm ON hm.id_module = m.id_module\nJOIN {prefix}hook h ON h.id_hook = hm.id_hook AND h.name = 'displayCarrierList'\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}module_shop ms ON ms.id_module = m.id_module AND ms.id_shop = s.id_shop\nWHERE ms.id_module IS NULL;",
            );
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf(
                '%d module(s) vérifiés — %d problème(s) détecté(s).',
                $moduleCount,
                count($issues)
            ),
            issues: $issues,
            check_query: "SELECT m.name, s.id_shop, s.name as shop_name\nFROM {prefix}module m\nJOIN {prefix}hook_module hm ON hm.id_module = m.id_module\nJOIN {prefix}hook h ON h.id_hook = hm.id_hook AND h.name = 'displayCarrierList'\nCROSS JOIN {prefix}shop s ON s.active = 1\nLEFT JOIN {prefix}module_shop ms ON ms.id_module = m.id_module AND ms.id_shop = s.id_shop\nWHERE ms.id_module IS NULL;",
        );
    }
}
