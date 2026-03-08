<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Service;

use Doctrine\DBAL\Connection;

/**
 * Service for analyzing multishop data integrity
 */
class AnalyzerService
{
    private Connection $connection;
    private string $prefix;

    public function __construct(Connection $connection, string $prefix)
    {
        $this->connection = $connection;
        $this->prefix = $prefix;
    }

    /**
     * Analyze shops
     *
     * @return array<string, mixed>
     */
    public function analyzeShops(): array
    {
        $sql = "
            SELECT id_shop, name, active
            FROM {$this->prefix}shop
            ORDER BY id_shop
        ";
        $shops = $this->connection->fetchAllAssociative($sql);

        $activeCount = count(array_filter($shops, fn ($s) => (bool) $s['active']));
        $totalCount = count($shops);
        $inactiveCount = $totalCount - $activeCount;

        return [
            'shops' => $shops,
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $inactiveCount,
            'status' => $inactiveCount > 0 ? 'warning' : 'ok',
        ];
    }

    /**
     * Analyze payment modules
     *
     * @return array<string, mixed>
     */
    public function analyzePaymentModules(): array
    {
        $sql = "
            SELECT
                m.id_module,
                m.name,
                m.active,
                COUNT(DISTINCT mc.id_shop) as shops_count,
                COUNT(DISTINCT mc.id_reference) as carriers_count
            FROM {$this->prefix}module m
            LEFT JOIN {$this->prefix}module_carrier mc ON m.id_module = mc.id_module
            WHERE m.name IN ('paypal', 'stripe_official')
            GROUP BY m.id_module
        ";
        $modules = $this->connection->fetchAllAssociative($sql);

        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $hasErrors = false;
        $hasWarnings = false;
        $modulesOk = 0;

        foreach ($modules as &$module) {
            $module['issues'] = [];

            if (!$module['active']) {
                $hasErrors = true;
                $module['issues'][] = [
                    'severity' => 'error',
                    'message' => 'Module inactif',
                    'recommendation' => 'Activer le module dans le back-office',
                ];
            } elseif ((int) $module['shops_count'] < $totalShops) {
                $hasWarnings = true;
                $module['issues'][] = [
                    'severity' => 'warning',
                    'message' => "Associé qu'à {$module['shops_count']}/{$totalShops} shops",
                    'recommendation' => 'Associer le module à tous les shops',
                ];
            } elseif ((int) $module['carriers_count'] === 0) {
                $hasWarnings = true;
                $module['issues'][] = [
                    'severity' => 'warning',
                    'message' => 'Aucun transporteur associé',
                    'recommendation' => 'Configurer les associations module-transporteur',
                ];
            } else {
                ++$modulesOk;
            }
        }
        unset($module);

        return [
            'modules' => $modules,
            'total_shops' => $totalShops,
            'has_errors' => $hasErrors,
            'has_warnings' => $hasWarnings,
            'modules_ok' => $modulesOk,
            'status' => $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'ok'),
        ];
    }

    /**
     * Analyze carriers
     *
     * @return array<string, mixed>
     */
    public function analyzeCarriers(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $sql = "
            SELECT
                c.id_carrier,
                c.id_reference,
                c.name,
                c.active,
                c.deleted,
                c.is_module,
                c.external_module_name,
                COUNT(DISTINCT cz.id_zone) as zones_count,
                COUNT(DISTINCT d.id_delivery) as delivery_count,
                COUNT(DISTINCT cs.id_shop) as shops_count
            FROM {$this->prefix}carrier c
            LEFT JOIN {$this->prefix}carrier_zone cz ON c.id_carrier = cz.id_carrier
            LEFT JOIN {$this->prefix}delivery d ON c.id_carrier = d.id_carrier
            LEFT JOIN {$this->prefix}carrier_shop cs ON c.id_carrier = cs.id_carrier
            WHERE c.deleted = 0
            GROUP BY c.id_carrier
            ORDER BY c.position
        ";
        $carriers = $this->connection->fetchAllAssociative($sql);

        $hasErrors = false;
        $hasWarnings = false;
        $carriersOk = 0;

        foreach ($carriers as &$carrier) {
            $carrier['issues'] = [];

            if ($carrier['active'] && (int) $carrier['shops_count'] < $totalShops) {
                $hasErrors = true;
                $carrier['issues'][] = [
                    'severity' => 'error',
                    'message' => "Associé qu'à {$carrier['shops_count']}/{$totalShops} shops",
                ];
            } elseif ($carrier['active'] && (int) $carrier['zones_count'] === 0) {
                $hasErrors = true;
                $carrier['issues'][] = [
                    'severity' => 'error',
                    'message' => 'Aucune zone de livraison',
                ];
            } elseif ($carrier['active'] && (int) $carrier['delivery_count'] === 0 && !$carrier['is_module']) {
                $hasErrors = true;
                $carrier['issues'][] = [
                    'severity' => 'error',
                    'message' => 'Aucun tarif configuré',
                ];
            } elseif ($carrier['active']) {
                ++$carriersOk;
            }
        }
        unset($carrier);

        $activeCount = count(array_filter($carriers, fn ($c) => (bool) $c['active']));

        return [
            'carriers' => $carriers,
            'total_shops' => $totalShops,
            'total' => count($carriers),
            'active' => $activeCount,
            'carriers_ok' => $carriersOk,
            'has_errors' => $hasErrors,
            'has_warnings' => $hasWarnings,
            'status' => $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'ok'),
        ];
    }

    /**
     * Check if stock sharing is enabled in shop_group
     *
     * @return array{share_stock: bool, group_name: string}
     */
    private function getShareStockConfig(): array
    {
        $group = $this->connection->fetchAssociative("
            SELECT id_shop_group, name, share_stock
            FROM {$this->prefix}shop_group
            WHERE id_shop_group = (
                SELECT id_shop_group FROM {$this->prefix}shop WHERE id_shop = 1 LIMIT 1
            )
        ");

        return [
            'share_stock' => (bool) ($group['share_stock'] ?? false),
            'group_name' => $group['name'] ?? 'Default',
        ];
    }

    /**
     * Analyze stocks
     *
     * @return array<string, mixed>
     */
    public function analyzeStocks(): array
    {
        $shareStockConfig = $this->getShareStockConfig();
        $shareStock = $shareStockConfig['share_stock'];

        $shop1Data = $this->connection->fetchAssociative("
            SELECT COUNT(*) as count, SUM(quantity) as total_quantity
            FROM {$this->prefix}stock_available
            WHERE id_shop = 1
        ");
        $countShop1 = (int) $shop1Data['count'];
        $quantityShop1 = $shop1Data['total_quantity'];

        $globalData = $this->connection->fetchAssociative("
            SELECT COUNT(*) as count, SUM(quantity) as total_quantity
            FROM {$this->prefix}stock_available
            WHERE id_shop = 0
        ");
        $countGlobal = (int) $globalData['count'];
        $quantityGlobal = $globalData['total_quantity'];

        $totalData = $this->connection->fetchAssociative("
            SELECT COUNT(*) as count, SUM(quantity) as total_quantity
            FROM {$this->prefix}stock_available
        ");
        $countTotal = (int) $totalData['count'];
        $quantityTotal = $totalData['total_quantity'];

        $shopDistribution = $this->connection->fetchAllAssociative("
            SELECT
                id_shop,
                COUNT(*) as count,
                SUM(quantity) as total_quantity,
                AVG(quantity) as avg_quantity,
                MIN(quantity) as min_quantity,
                MAX(quantity) as max_quantity,
                COUNT(DISTINCT id_product) as unique_products,
                SUM(CASE WHEN quantity IS NULL THEN 1 ELSE 0 END) as null_quantities
            FROM {$this->prefix}stock_available
            GROUP BY id_shop
            ORDER BY id_shop
        ");

        $diagnostic = '';
        $diagnosticType = 'ok';
        $issues = [];

        if ($countTotal === 0) {
            $diagnostic = 'CATASTROPHE : AUCUN STOCK EXISTANT !';
            $diagnosticType = 'error';
            $issues[] = [
                'severity' => 'error',
                'category' => 'Stocks',
                'description' => 'Tous les stocks ont été perdus',
                'recommendation' => 'Restaurer depuis une sauvegarde',
            ];
        } elseif ($countShop1 === 0 && $countGlobal === 0) {
            $diagnostic = 'CATASTROPHE : Stocks vides dans les shops 0 et 1 !';
            $diagnosticType = 'error';
            $issues[] = [
                'severity' => 'error',
                'category' => 'Stocks',
                'description' => 'Stocks vides dans les shops principaux',
                'recommendation' => 'Vérifier où sont les stocks',
            ];
        } elseif ($countShop1 === 0 && $countGlobal > 0) {
            if ($shareStock) {
                $diagnostic = 'Configuration correcte : les stocks sont centralisés dans le shop 0 (global) car le partage de stock est activé';
                $diagnosticType = 'ok';
            } else {
                $diagnostic = 'Les stocks du shop 1 sont vides, mais il y a des stocks dans le shop 0 (global). Le partage de stock est désactivé, ce qui peut poser problème.';
                $diagnosticType = 'warning';
                $issues[] = [
                    'severity' => 'warning',
                    'category' => 'Stocks',
                    'description' => 'Stocks dans shop 0 mais share_stock désactivé',
                    'recommendation' => 'Activer share_stock ou dupliquer les stocks vers les shops individuels',
                ];
            }
        } elseif ($countShop1 === 0) {
            $diagnostic = 'Les stocks du shop 1 sont vides';
            $diagnosticType = 'warning';
            $issues[] = [
                'severity' => 'warning',
                'category' => 'Stocks',
                'description' => "Le shop 1 n'a aucun stock",
                'recommendation' => 'Vérifier si les stocks sont dans le shop 0',
            ];
        } elseif ($quantityShop1 === null) {
            $diagnostic = 'Le shop 1 a des lignes, mais avec quantity = NULL';
            $diagnosticType = 'warning';
            $issues[] = [
                'severity' => 'warning',
                'category' => 'Stocks',
                'description' => 'Quantités NULL détectées dans le shop 1',
                'recommendation' => 'Corriger les quantités NULL',
            ];
        } elseif ($shareStock && $countShop1 > 0 && $countGlobal === 0) {
            $diagnostic = 'Le partage de stock est activé, mais les stocks sont dans les shops individuels au lieu du shop 0 (global)';
            $diagnosticType = 'warning';
            $issues[] = [
                'severity' => 'warning',
                'category' => 'Stocks',
                'description' => 'share_stock activé mais stocks non centralisés',
                'recommendation' => 'Migrer les stocks vers le shop 0 pour un fonctionnement correct du partage',
            ];
        } else {
            if ($shareStock) {
                if ($countShop1 > 0) {
                    $diagnostic = "Le partage de stock est activé, mais {$countShop1} entrée(s) restent dans le shop 1 au lieu du shop 0 (global). Le fix 'stocks' doit être exécuté.";
                    $diagnosticType = 'warning';
                    $issues[] = [
                        'severity' => 'warning',
                        'category' => 'Stocks',
                        'description' => "share_stock activé mais {$countShop1} entrées restent dans id_shop=1",
                        'recommendation' => "Exécuter le fix 'stocks' pour migrer vers id_shop=0",
                    ];
                } else {
                    $diagnostic = 'Configuration correcte : les stocks sont centralisés dans le shop 0 (global)';
                    $diagnosticType = 'ok';
                }
            } else {
                $diagnostic = 'Le shop 1 semble avoir des stocks normaux';
                $diagnosticType = 'ok';
            }
        }

        $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;
        $productsWithoutStock = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}product p
            WHERE p.active = 1
              AND p.id_product NOT IN (?)
              AND NOT EXISTS (
                  SELECT 1
                  FROM {$this->prefix}stock_available sa
                  WHERE sa.id_product = p.id_product
              )
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        if ($productsWithoutStock > 0) {
            $issues[] = [
                'severity' => 'warning',
                'category' => 'Stocks',
                'description' => "{$productsWithoutStock} produit(s) actif(s) sans entrée de stock",
                'recommendation' => 'Créer les entrées de stock manquantes',
            ];
        }

        return [
            'count_shop1' => $countShop1,
            'quantity_shop1' => $quantityShop1,
            'count_global' => $countGlobal,
            'quantity_global' => $quantityGlobal,
            'count_total' => $countTotal,
            'quantity_total' => $quantityTotal,
            'shop_distribution' => $shopDistribution,
            'diagnostic' => $diagnostic,
            'diagnostic_type' => $diagnosticType,
            'products_without_stock' => $productsWithoutStock,
            'share_stock' => $shareStock,
            'share_stock_group' => $shareStockConfig['group_name'],
            'issues' => $issues,
            'status' => $diagnosticType,
        ];
    }

    /**
     * Analyze products for multishop completeness
     *
     * @return array<string, mixed>
     */
    public function analyzeProducts(): array
    {
        $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;

        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $sourceShopId = (int) $this->connection->fetchOne(
            "SELECT MIN(id_shop) FROM {$this->prefix}shop WHERE active = 1"
        );

        $totalLangs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}lang WHERE active = 1"
        );

        $totalProducts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}product p WHERE p.active = 1 AND p.id_product NOT IN (?)",
            [$excludedIds],
            [Connection::PARAM_INT_ARRAY]
        );

        $steps = [];
        $totalMissing = 0;

        // Step 1: product_lang
        $productLangSource = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}product_lang pl
            INNER JOIN {$this->prefix}product p ON pl.id_product = p.id_product
            WHERE pl.id_shop = ? AND p.active = 1 AND p.id_product NOT IN (?)
        ", [$sourceShopId, $excludedIds], [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]);

        $productLangMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}product_lang pl_source
            INNER JOIN {$this->prefix}product p ON pl_source.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            WHERE pl_source.id_shop = ?
            AND p.active = 1 AND p.id_product NOT IN (?)
            AND s.active = 1 AND s.id_shop != ?
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_lang pl
                WHERE pl.id_product = pl_source.id_product
                AND pl.id_lang = pl_source.id_lang AND pl.id_shop = s.id_shop
            )
        ", [$sourceShopId, $excludedIds, $sourceShopId], [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT]);
        $totalMissing += $productLangMissing;

        $steps[] = [
            'table' => 'product_lang',
            'label' => 'Traductions produits',
            'total' => $productLangSource,
            'missing' => $productLangMissing,
            'status' => $productLangMissing > 0 ? 'warning' : 'ok',
        ];

        // Step 2: product_shop
        $productShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}product p
            CROSS JOIN {$this->prefix}shop s
            WHERE p.active = 1 AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_shop ps
                WHERE ps.id_product = p.id_product AND ps.id_shop = s.id_shop
            )
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);
        $totalMissing += $productShopMissing;

        $steps[] = [
            'table' => 'product_shop',
            'label' => 'Associations produit-shop',
            'total' => $totalProducts,
            'missing' => $productShopMissing,
            'status' => $productShopMissing > 0 ? 'error' : 'ok',
        ];

        // Step 3: product_attribute_shop
        $totalCombinations = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT pa.id_product_attribute)
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            WHERE p.active = 1 AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);
        $combinationsMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}product_attribute pa
            INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
            CROSS JOIN {$this->prefix}shop s
            WHERE p.active = 1 AND p.id_product NOT IN (?)
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}product_attribute_shop pas
                WHERE pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = s.id_shop
            )
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);
        $totalMissing += $combinationsMissing;

        $defaultOnMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT pa.id_product, pas.id_shop
                FROM {$this->prefix}product_attribute pa
                INNER JOIN {$this->prefix}product p ON pa.id_product = p.id_product
                INNER JOIN {$this->prefix}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute
                WHERE p.active = 1 AND p.id_product NOT IN (?)
                GROUP BY pa.id_product, pas.id_shop
                HAVING SUM(CASE WHEN pas.default_on = 1 THEN 1 ELSE 0 END) = 0
            ) as m
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);
        $totalMissing += $defaultOnMissing;

        $combinationsTotal = $combinationsMissing + $defaultOnMissing;
        $combinationsDetail = sprintf(
            'Entrées manquantes: %d, default_on manquant: %d',
            $combinationsMissing,
            $defaultOnMissing
        );

        $steps[] = [
            'table' => 'product_attribute_shop',
            'label' => 'Déclinaisons (combinaisons)',
            'total' => $totalCombinations,
            'missing' => $combinationsTotal,
            'status' => $combinationsTotal > 0 ? 'error' : 'ok',
            'detail' => $combinationsDetail,
        ];

        // Step 4: attribute_shop
        $totalAttributes = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->prefix}attribute");
        $attributeShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT a.id_attribute FROM {$this->prefix}attribute a
                LEFT JOIN {$this->prefix}attribute_shop ash ON a.id_attribute = ash.id_attribute
                GROUP BY a.id_attribute
                HAVING COUNT(DISTINCT ash.id_shop) < ?
            ) as m
        ", [$totalShops]);
        $totalMissing += $attributeShopMissing;

        $steps[] = [
            'table' => 'attribute_shop',
            'label' => 'Attributs',
            'total' => $totalAttributes,
            'missing' => $attributeShopMissing,
            'status' => $attributeShopMissing > 0 ? 'warning' : 'ok',
        ];

        // Step 5: category_lang
        $totalCategories = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->prefix}category WHERE active = 1");
        $categoryLangSource = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM {$this->prefix}category_lang cl
            INNER JOIN {$this->prefix}category c ON cl.id_category = c.id_category
            WHERE cl.id_shop = ? AND c.active = 1
        ", [$sourceShopId]);
        $categoryLangMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT c.id_category FROM {$this->prefix}category c
                LEFT JOIN {$this->prefix}category_lang cl ON c.id_category = cl.id_category
                WHERE c.active = 1 GROUP BY c.id_category
                HAVING COUNT(DISTINCT cl.id_shop) < ?
            ) as m
        ", [$totalShops]);
        $totalMissing += $categoryLangMissing;

        $steps[] = [
            'table' => 'category_lang',
            'label' => 'Traductions catégories',
            'total' => $categoryLangSource,
            'missing' => $categoryLangMissing,
            'status' => $categoryLangMissing > 0 ? 'warning' : 'ok',
        ];

        // Step 6: category_shop
        $categoryShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT c.id_category FROM {$this->prefix}category c
                LEFT JOIN {$this->prefix}category_shop cs ON c.id_category = cs.id_category
                WHERE c.active = 1 GROUP BY c.id_category
                HAVING COUNT(DISTINCT cs.id_shop) < ?
            ) as m
        ", [$totalShops]);
        $totalMissing += $categoryShopMissing;

        $steps[] = [
            'table' => 'category_shop',
            'label' => 'Associations catégorie-shop',
            'total' => $totalCategories,
            'missing' => $categoryShopMissing,
            'status' => $categoryShopMissing > 0 ? 'warning' : 'ok',
        ];

        // Step 7: specific_price
        $totalSpecificPrices = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.id_product > 0 AND p.active = 1 AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $globalSpecificPrices = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.id_shop = 0 AND sp.id_product > 0 AND p.active = 1 AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $shopSpecificPrices = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.id_shop = ? AND sp.id_product > 0 AND p.active = 1 AND p.id_product NOT IN (?)
        ", [$sourceShopId, $excludedIds], [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]);

        $specificPriceMissing = 0;
        if ($shopSpecificPrices > 0) {
            $specificPriceMissing = (int) $this->connection->fetchOne("
                SELECT COUNT(*)
                FROM {$this->prefix}specific_price sp_source
                INNER JOIN {$this->prefix}product p ON sp_source.id_product = p.id_product
                CROSS JOIN {$this->prefix}shop s
                WHERE sp_source.id_shop = ?
                AND sp_source.id_product > 0
                AND p.active = 1
                AND p.id_product NOT IN (?)
                AND s.active = 1
                AND s.id_shop != ?
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->prefix}specific_price sp
                    WHERE sp.id_product = sp_source.id_product
                    AND sp.id_product_attribute = sp_source.id_product_attribute
                    AND (sp.id_shop = s.id_shop OR sp.id_shop = 0)
                    AND sp.id_currency = sp_source.id_currency
                    AND sp.id_country = sp_source.id_country
                    AND sp.id_group = sp_source.id_group
                    AND sp.from_quantity = sp_source.from_quantity
                )
            ", [$sourceShopId, $excludedIds, $sourceShopId], [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT]);
            $totalMissing += $specificPriceMissing;
        }

        $steps[] = [
            'table' => 'specific_price',
            'label' => 'Promotions (prix spécifiques)',
            'total' => $totalSpecificPrices,
            'missing' => $specificPriceMissing,
            'status' => $specificPriceMissing > 0 ? 'warning' : 'ok',
            'detail' => "Global: {$globalSpecificPrices}, Shop-specific: {$shopSpecificPrices}",
        ];

        // Step 8: feature_shop
        $totalFeatures = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->prefix}feature");
        $featureShopMissing = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT f.id_feature FROM {$this->prefix}feature f
                LEFT JOIN {$this->prefix}feature_shop fs ON f.id_feature = fs.id_feature
                GROUP BY f.id_feature
                HAVING COUNT(DISTINCT fs.id_shop) < ?
            ) as m
        ", [$totalShops]);
        $totalMissing += $featureShopMissing;

        $steps[] = [
            'table' => 'feature_shop',
            'label' => 'Caractéristiques',
            'total' => $totalFeatures,
            'missing' => $featureShopMissing,
            'status' => $featureShopMissing > 0 ? 'warning' : 'ok',
        ];

        // Step 9: price anomaly check
        $priceZeroInShop = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT ps.id_product)
            FROM {$this->prefix}product_shop ps
            INNER JOIN {$this->prefix}product p ON p.id_product = ps.id_product
            WHERE ps.price = 0
            AND p.price > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $specificPriceZero = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT sp.id_product)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.price = 0
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $specificPrice100Off = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT sp.id_product)
            FROM {$this->prefix}specific_price sp
            INNER JOIN {$this->prefix}product p ON sp.id_product = p.id_product
            WHERE sp.reduction_type = 'percentage'
            AND sp.reduction >= 1
            AND sp.id_product > 0
            AND p.active = 1
            AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $priceAnomalyTotal = $priceZeroInShop + $specificPriceZero + $specificPrice100Off;
        $totalMissing += $priceAnomalyTotal;

        $priceDetail = sprintf(
            'product_shop.price=0: %d, specific_price.price=0: %d, réduction 100%%: %d',
            $priceZeroInShop,
            $specificPriceZero,
            $specificPrice100Off
        );

        $steps[] = [
            'table' => 'product_shop',
            'label' => 'Anomalies prix (affichage 0€)',
            'total' => $totalProducts,
            'missing' => $priceAnomalyTotal,
            'status' => $priceAnomalyTotal > 0 ? 'error' : 'ok',
            'detail' => $priceDetail,
        ];

        $hasErrors = $productShopMissing > 0 || $combinationsTotal > 0 || $priceAnomalyTotal > 0;
        $hasWarnings = $totalMissing > 0;

        return [
            'total_products' => $totalProducts,
            'total_shops' => $totalShops,
            'total_langs' => $totalLangs,
            'source_shop' => $sourceShopId,
            'total_missing' => $totalMissing,
            'steps' => $steps,
            'status' => $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'ok'),
        ];
    }
}
