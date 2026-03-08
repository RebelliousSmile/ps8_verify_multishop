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
 * Service for detailed diagnostics
 */
class DiagnosticsService
{
    private Connection $connection;
    private string $prefix;
    private string $projectDir;
    private ConfigFixer $configFixer;

    public function __construct(Connection $connection, string $prefix, string $projectDir, ConfigFixer $configFixer)
    {
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->projectDir = $projectDir;
        $this->configFixer = $configFixer;
    }

    /**
     * Check module configurations for blocking NULLs in multishop context
     *
     * @return array{status: string, modules_with_issues: array, total_issues: int}
     */
    public function checkModuleConfigurations(): array
    {
        $result = $this->configFixer->preview('module_config');

        $modulesWithIssues = $result['modules'] ?? [];
        $totalIssues = array_sum(array_column($modulesWithIssues, 'issue_count'));

        return [
            'status' => $totalIssues > 0 ? 'warning' : 'ok',
            'modules_with_issues' => $modulesWithIssues,
            'total_issues' => $totalIssues,
        ];
    }

    /**
     * Check module configurations for shop values overriding the global value
     *
     * @return array{status: string, modules_with_issues: array, total_issues: int}
     */
    public function checkShopOverrideConfigurations(): array
    {
        $result = $this->configFixer->preview('shop_override');

        $modulesWithIssues = $result['modules'] ?? [];
        $totalIssues = array_sum(array_column($modulesWithIssues, 'issue_count'));

        return [
            'status' => $totalIssues > 0 ? 'warning' : 'ok',
            'modules_with_issues' => $modulesWithIssues,
            'total_issues' => $totalIssues,
        ];
    }

    /**
     * Check shop group sharing settings (CRITICAL - prerequisite for all other fixes)
     *
     * @return array<string, mixed>
     */
    public function checkShopGroupSharing(): array
    {
        $shopGroups = $this->connection->fetchAllAssociative("
            SELECT
                id_shop_group,
                name,
                share_customer,
                share_order,
                share_stock,
                active,
                deleted
            FROM {$this->prefix}shop_group
            ORDER BY id_shop_group
        ");

        $issues = [];
        $status = 'ok';
        $allSharesEnabled = true;

        foreach ($shopGroups as $group) {
            $groupIssues = [];

            if (!(bool) $group['share_customer']) {
                $groupIssues[] = 'share_customer = 0';
                $allSharesEnabled = false;
            }
            if (!(bool) $group['share_order']) {
                $groupIssues[] = 'share_order = 0';
                $allSharesEnabled = false;
            }
            if (!(bool) $group['share_stock']) {
                $groupIssues[] = 'share_stock = 0';
                $allSharesEnabled = false;
            }

            if (!empty($groupIssues)) {
                $issues[] = [
                    'severity' => 'error',
                    'description' => "Shop Group {$group['id_shop_group']} ({$group['name']}): " . implode(', ', $groupIssues),
                    'recommendation' => 'Activer le partage avant toute autre correction',
                ];
                $status = 'error';
            }
        }

        if ($allSharesEnabled) {
            $issues[] = [
                'severity' => 'success',
                'description' => 'Tous les paramètres de partage sont activés',
            ];
        }

        return [
            'shop_groups' => $shopGroups,
            'all_shares_enabled' => $allSharesEnabled,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * Check shop URLs
     *
     * @return array<string, mixed>
     */
    public function checkShopUrls(): array
    {
        $urls = $this->connection->fetchAllAssociative("
            SELECT s.id_shop, s.name, su.domain, su.domain_ssl, su.main, su.active
            FROM {$this->prefix}shop s
            LEFT JOIN {$this->prefix}shop_url su ON s.id_shop = su.id_shop
            WHERE s.active = 1
            ORDER BY s.id_shop
        ");

        $rows = [];
        foreach ($urls as $url) {
            $rows[] = [
                'id_shop' => $url['id_shop'],
                'name' => $url['name'],
                'domain' => $url['domain'] ?: 'NON CONFIGURÉ',
                'domain_ssl' => $url['domain_ssl'] ?: 'NON CONFIGURÉ',
                'main' => (bool) $url['main'],
                'active' => (bool) $url['active'],
            ];
        }

        $missingUrls = array_filter($urls, fn ($u) => empty($u['domain']));
        $issues = [];

        if (count($missingUrls) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => count($missingUrls) . ' shop(s) sans URL configurée',
            ];
        }

        return [
            'urls' => $rows,
            'issues' => $issues,
            'status' => count($missingUrls) > 0 ? 'warning' : 'ok',
        ];
    }

    /**
     * Check prices
     *
     * @return array<string, mixed>
     */
    public function checkPrices(): array
    {
        $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;

        $shops = $this->connection->fetchAllAssociative(
            "SELECT id_shop, name FROM {$this->prefix}shop WHERE active = 1 ORDER BY id_shop"
        );

        $stats = [];
        foreach ($shops as $shop) {
            $row = $this->connection->fetchAssociative("
                SELECT
                    COUNT(*) as total,
                    COUNT(CASE WHEN price > 0 THEN 1 END) as price_gt_0,
                    COUNT(CASE WHEN price = 0 THEN 1 END) as price_eq_0,
                    COUNT(CASE WHEN price IS NULL THEN 1 END) as price_null
                FROM {$this->prefix}product_shop ps
                WHERE id_shop = ? AND id_product NOT IN (?)
            ", [$shop['id_shop'], $excludedIds], [\PDO::PARAM_INT, Connection::PARAM_INT_ARRAY]);

            $stats[] = [
                'shop_id' => $shop['id_shop'],
                'shop_name' => $shop['name'],
                'total' => (int) $row['total'],
                'price_gt_0' => (int) $row['price_gt_0'],
                'price_eq_0' => (int) $row['price_eq_0'],
                'price_null' => (int) $row['price_null'],
            ];
        }

        $hasIssues = array_filter($stats, fn ($s) => $s['price_eq_0'] > 0 || $s['price_null'] > 0);

        return [
            'stats' => $stats,
            'issues' => count($hasIssues) > 0 ? [['severity' => 'warning', 'description' => 'Prix à 0 ou NULL détectés']] : [],
            'status' => count($hasIssues) > 0 ? 'warning' : 'ok',
        ];
    }

    /**
     * Check images
     *
     * @return array<string, mixed>
     */
    public function checkImages(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );
        $excludedIds = MultishopConstants::EXCLUDED_PRODUCTS;

        $totalImages = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT i.id_image)
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1 AND p.id_product NOT IN (?)
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $productsWithoutImages = $this->connection->fetchAllAssociative("
            SELECT p.id_product, pl.name
            FROM {$this->prefix}product p
            LEFT JOIN {$this->prefix}product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = 1)
            LEFT JOIN {$this->prefix}image i ON p.id_product = i.id_product
            WHERE p.active = 1 AND p.id_product NOT IN (?)
            GROUP BY p.id_product
            HAVING COUNT(i.id_image) = 0
            LIMIT 20
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $productsWithoutCover = $this->connection->fetchAllAssociative("
            SELECT p.id_product, pl.name
            FROM {$this->prefix}product p
            LEFT JOIN {$this->prefix}product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = 1)
            LEFT JOIN {$this->prefix}image i ON (p.id_product = i.id_product AND i.cover = 1)
            WHERE p.active = 1 AND p.id_product NOT IN (?)
            AND i.id_image IS NULL
            GROUP BY p.id_product
            LIMIT 20
        ", [$excludedIds], [Connection::PARAM_INT_ARRAY]);

        $imagesMissingShops = (int) $this->connection->fetchOne("
            SELECT COUNT(*) FROM (
                SELECT i.id_image
                FROM {$this->prefix}image i
                INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
                LEFT JOIN {$this->prefix}image_shop ish ON i.id_image = ish.id_image
                WHERE p.active = 1 AND p.id_product NOT IN (?)
                GROUP BY i.id_image
                HAVING COUNT(DISTINCT ish.id_shop) < ?
            ) as missing
        ", [$excludedIds, $totalShops], [Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT]);

        $issues = [];
        $status = 'ok';

        if (count($productsWithoutImages) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => count($productsWithoutImages) . ' produit(s) sans images',
            ];
            $status = 'warning';
        }

        if (count($productsWithoutCover) > 0) {
            $issues[] = [
                'severity' => 'error',
                'description' => count($productsWithoutCover) . ' produit(s) sans image de couverture - CRITIQUE',
            ];
            $status = 'error';
        }

        if ($imagesMissingShops > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => "{$imagesMissingShops} image(s) non associées à tous les shops",
            ];
            if ($status !== 'error') {
                $status = 'warning';
            }
        }

        return [
            'total_images' => $totalImages,
            'total_shops' => $totalShops,
            'products_without_images' => $productsWithoutImages,
            'products_without_cover' => $productsWithoutCover,
            'images_missing_shops' => $imagesMissingShops,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * Check currencies
     *
     * @return array<string, mixed>
     */
    public function checkCurrencies(): array
    {
        $currencies = $this->connection->fetchAllAssociative("
            SELECT id_currency, name, iso_code, conversion_rate, active
            FROM {$this->prefix}currency
            ORDER BY active DESC, iso_code
        ");

        $shopCurrencies = $this->connection->fetchAllAssociative("
            SELECT s.id_shop, s.name as shop_name, GROUP_CONCAT(c.iso_code ORDER BY c.iso_code) as currencies
            FROM {$this->prefix}shop s
            LEFT JOIN {$this->prefix}currency_shop cs ON s.id_shop = cs.id_shop
            LEFT JOIN {$this->prefix}currency c ON cs.id_currency = c.id_currency AND c.active = 1
            WHERE s.active = 1
            GROUP BY s.id_shop
            ORDER BY s.id_shop
        ");

        $issues = [];

        $chfForCH = array_filter($shopCurrencies, function ($sc) {
            return (int) $sc['id_shop'] === 17 && strpos($sc['currencies'] ?? '', 'CHF') !== false;
        });

        if (count($chfForCH) === 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => "Le shop Suisse (17) n'a pas CHF configuré",
            ];
        }

        return [
            'currencies' => $currencies,
            'shop_currencies' => $shopCurrencies,
            'issues' => $issues,
            'status' => count($issues) > 0 ? 'warning' : 'ok',
        ];
    }

    /**
     * Check CMS pages
     *
     * @return array<string, mixed>
     */
    public function checkCmsPages(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            "SELECT id_shop, name FROM {$this->prefix}shop ORDER BY id_shop"
        );
        $totalShops = count($shops);

        $languages = $this->connection->fetchAllAssociative(
            "SELECT id_lang, name, iso_code FROM {$this->prefix}lang WHERE active = 1 ORDER BY id_lang"
        );
        $totalLangs = count($languages);

        $rootCategory = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->prefix}cms_category WHERE id_cms_category = 1"
        );

        $issues = [];
        $status = 'ok';

        if (!$rootCategory) {
            $issues[] = [
                'severity' => 'error',
                'description' => 'Catégorie racine CMS (ID=1) manquante - CRITIQUE',
            ];
            $status = 'error';
        }

        $missingShopAssoc = [];
        foreach ($shops as $shop) {
            $exists = $this->connection->fetchOne(
                "SELECT 1 FROM {$this->prefix}cms_category_shop WHERE id_cms_category = 1 AND id_shop = ?",
                [$shop['id_shop']]
            );
            if (!$exists) {
                $missingShopAssoc[] = $shop['id_shop'];
            }
        }

        if (count($missingShopAssoc) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => count($missingShopAssoc) . ' shop(s) non associé(s) à la catégorie racine CMS',
            ];
            if ($status !== 'error') {
                $status = 'warning';
            }
        }

        $existingTranslations = $this->connection->fetchAllAssociative("
            SELECT ccl.id_cms_category, ccl.id_lang, ccl.id_shop, ccl.name
            FROM {$this->prefix}cms_category_lang ccl
            WHERE ccl.id_cms_category = 1
            ORDER BY ccl.id_shop, ccl.id_lang
        ");

        $translationMap = [];
        foreach ($existingTranslations as $trans) {
            $translationMap[$trans['id_shop'] . '_' . $trans['id_lang']] = true;
        }

        $missingTranslations = 0;
        foreach ($shops as $shop) {
            foreach ($languages as $lang) {
                if (!isset($translationMap[$shop['id_shop'] . '_' . $lang['id_lang']])) {
                    ++$missingTranslations;
                }
            }
        }

        if ($missingTranslations > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => "{$missingTranslations} traduction(s) manquante(s) pour la catégorie racine CMS",
            ];
            if ($status !== 'error') {
                $status = 'warning';
            }
        }

        return [
            'root_category_exists' => (bool) $rootCategory,
            'total_shops' => $totalShops,
            'total_langs' => $totalLangs,
            'missing_shop_associations' => $missingShopAssoc,
            'missing_translations' => $missingTranslations,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * Check meta pages
     *
     * @return array<string, mixed>
     */
    public function checkMetaPages(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );
        $totalLangs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}lang WHERE active = 1"
        );

        $metas = $this->connection->fetchAllAssociative(
            "SELECT id_meta, page, configurable FROM {$this->prefix}meta ORDER BY page"
        );
        $totalMetas = count($metas);

        $metasWithIssues = [];
        $totalMissing = 0;

        foreach ($metas as $meta) {
            $existingCount = (int) $this->connection->fetchOne("
                SELECT COUNT(DISTINCT CONCAT(id_shop, '_', id_lang))
                FROM {$this->prefix}meta_lang
                WHERE id_meta = ?
            ", [$meta['id_meta']]);

            $expectedCount = $totalShops * $totalLangs;
            $missing = $expectedCount - $existingCount;

            if ($missing > 0) {
                $metasWithIssues[] = [
                    'id_meta' => $meta['id_meta'],
                    'page' => $meta['page'],
                    'existing' => $existingCount,
                    'expected' => $expectedCount,
                    'missing' => $missing,
                ];
                $totalMissing += $missing;
            }
        }

        $noTranslations = $this->connection->fetchAllAssociative("
            SELECT m.id_meta, m.page
            FROM {$this->prefix}meta m
            LEFT JOIN {$this->prefix}meta_lang ml ON m.id_meta = ml.id_meta
            WHERE ml.id_meta IS NULL
        ");

        $issues = [];
        $status = 'ok';

        if (count($metasWithIssues) > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => count($metasWithIssues) . " page(s) Meta avec traductions manquantes ($totalMissing au total)",
            ];
            $status = 'warning';
        }

        if (count($noTranslations) > 0) {
            $issues[] = [
                'severity' => 'error',
                'description' => count($noTranslations) . ' page(s) Meta SANS AUCUNE traduction - CRITIQUE',
            ];
            $status = 'error';
        }

        return [
            'total_metas' => $totalMetas,
            'total_shops' => $totalShops,
            'total_langs' => $totalLangs,
            'metas_with_issues' => $metasWithIssues,
            'total_missing' => $totalMissing,
            'no_translations' => $noTranslations,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * Check image_shop associations for multishop
     *
     * @return array<string, mixed>
     */
    public function checkImageShop(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );

        $totalImages = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT i.id_image)
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
        ");

        $missingCount = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}image i
            CROSS JOIN {$this->prefix}shop s
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
            AND s.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}image_shop ish
                WHERE ish.id_image = i.id_image
                AND ish.id_shop = s.id_shop
            )
        ");

        $extensionStats = $this->countImagesByExtension();

        $shopDistribution = $this->connection->fetchAllAssociative("
            SELECT
                s.id_shop,
                s.name as shop_name,
                COUNT(DISTINCT ish.id_image) as images_count,
                COUNT(DISTINCT CASE WHEN ish.cover = 1 THEN ish.id_image END) as covers_count
            FROM {$this->prefix}shop s
            LEFT JOIN {$this->prefix}image_shop ish ON s.id_shop = ish.id_shop
            WHERE s.active = 1
            GROUP BY s.id_shop, s.name
            ORDER BY s.id_shop
        ");

        foreach ($shopDistribution as &$shop) {
            $diff = $totalImages - (int) $shop['images_count'];
            $shop['missing_count'] = max(0, $diff);
            $shop['extra_count'] = max(0, -$diff);
            $shop['non_covers_count'] = (int) $shop['images_count'] - (int) $shop['covers_count'];
        }
        unset($shop);

        $orphanEntries = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}image_shop ish
            INNER JOIN {$this->prefix}image i ON ish.id_image = i.id_image
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 0
        ");

        $issues = [];
        $status = 'ok';

        if ($missingCount > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => "{$missingCount} image(s) sans association shop",
                'recommendation' => "Exécuter le fix 'image_shop' pour associer les images à tous les shops",
            ];
            $status = 'warning';
        }

        if ($orphanEntries > 0) {
            $issues[] = [
                'severity' => 'warning',
                'description' => "{$orphanEntries} entrée(s) orpheline(s) (produits inactifs)",
                'recommendation' => "Exécuter le fix 'image_shop' pour nettoyer les entrées orphelines",
            ];
            if ($status !== 'error') {
                $status = 'warning';
            }
        }

        $serverCapabilities = $this->checkImageCapabilities();

        return [
            'total_images' => $totalImages,
            'total_shops' => $totalShops,
            'missing_associations' => $missingCount,
            'orphan_entries' => $orphanEntries,
            'shop_distribution' => $shopDistribution,
            'extension_stats' => $extensionStats,
            'server_capabilities' => $serverCapabilities,
            'issues' => $issues,
            'status' => $status,
        ];
    }

    /**
     * Check PHP image processing capabilities
     *
     * @return array<string, mixed>
     */
    private function checkImageCapabilities(): array
    {
        $capabilities = [
            'gd' => [
                'installed' => extension_loaded('gd'),
                'version' => null,
                'webp' => false,
                'jpg' => false,
                'png' => false,
                'gif' => false,
            ],
            'imagick' => [
                'installed' => extension_loaded('imagick'),
                'version' => null,
                'webp' => false,
                'formats' => [],
            ],
            'webp_available' => false,
            'recommended_library' => null,
        ];

        if ($capabilities['gd']['installed']) {
            $gdInfo = gd_info();
            $capabilities['gd']['version'] = $gdInfo['GD Version'] ?? 'Unknown';
            $capabilities['gd']['webp'] = !empty($gdInfo['WebP Support']);
            $capabilities['gd']['jpg'] = !empty($gdInfo['JPEG Support']);
            $capabilities['gd']['png'] = !empty($gdInfo['PNG Support']);
            $capabilities['gd']['gif'] = !empty($gdInfo['GIF Read Support']);
        }

        if ($capabilities['imagick']['installed']) {
            try {
                $imagick = new \Imagick();
                $capabilities['imagick']['version'] = $imagick->getVersion()['versionString'] ?? 'Unknown';
                $formats = \Imagick::queryFormats();
                $capabilities['imagick']['formats'] = $formats;
                $capabilities['imagick']['webp'] = in_array('WEBP', $formats, true);
            } catch (\Exception $e) {
                $capabilities['imagick']['version'] = 'Error: ' . $e->getMessage();
            }
        }

        $capabilities['webp_available'] = $capabilities['gd']['webp'] || $capabilities['imagick']['webp'];

        if ($capabilities['imagick']['installed'] && $capabilities['imagick']['webp']) {
            $capabilities['recommended_library'] = 'ImageMagick';
        } elseif ($capabilities['gd']['installed'] && $capabilities['gd']['webp']) {
            $capabilities['recommended_library'] = 'GD';
        } elseif ($capabilities['imagick']['installed']) {
            $capabilities['recommended_library'] = 'ImageMagick (sans WebP)';
        } elseif ($capabilities['gd']['installed']) {
            $capabilities['recommended_library'] = 'GD (sans WebP)';
        } else {
            $capabilities['recommended_library'] = 'Aucune bibliothèque disponible';
        }

        return $capabilities;
    }

    /**
     * Count product images by file extension and WebP thumbnail coverage
     *
     * @return array<string, mixed>
     */
    private function countImagesByExtension(): array
    {
        $stats = [
            'has_original' => 0,
            'thumbs_only' => 0,
            'not_found' => 0,
        ];

        $imageIds = $this->connection->fetchFirstColumn("
            SELECT DISTINCT i.id_image
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
            ORDER BY i.id_image
        ");

        $imageTypes = $this->connection->fetchAllAssociative("
            SELECT name, width, height FROM {$this->prefix}image_type WHERE products = 1 ORDER BY name
        ");
        $expectedThumbsPerImage = count($imageTypes);

        $typeStats = [];
        foreach ($imageTypes as $type) {
            $typeStats[$type['name']] = [
                'name' => $type['name'],
                'width' => (int) $type['width'],
                'height' => (int) $type['height'],
                'webp_count' => 0,
                'jpg_count' => 0,
                'missing_count' => 0,
            ];
        }

        $imgPath = $this->projectDir . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'p' . DIRECTORY_SEPARATOR;

        $sampleSize = min(100, count($imageIds));
        $sampleIds = array_slice($imageIds, 0, $sampleSize);

        $webpThumbsCount = 0;
        $expectedThumbsTotal = 0;
        $imagesWithAllWebp = 0;

        foreach ($sampleIds as $imageId) {
            $subPath = implode(DIRECTORY_SEPARATOR, str_split((string) $imageId)) . DIRECTORY_SEPARATOR;
            $basePath = $imgPath . $subPath . $imageId;
            $dirPath = $imgPath . $subPath;

            $foundOriginal = false;
            foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
                if (file_exists($basePath . '.' . $ext)) {
                    $foundOriginal = true;
                    break;
                }
            }

            $foundThumb = false;
            $imageWebpCount = 0;

            if (is_dir($dirPath)) {
                foreach ($imageTypes as $type) {
                    $typeName = $type['name'];
                    $webpPath = $dirPath . $imageId . '-' . $typeName . '.webp';
                    $jpgPath = $dirPath . $imageId . '-' . $typeName . '.jpg';

                    if (file_exists($webpPath)) {
                        ++$typeStats[$typeName]['webp_count'];
                        ++$webpThumbsCount;
                        ++$imageWebpCount;
                        $foundThumb = true;
                    } elseif (file_exists($jpgPath)) {
                        ++$typeStats[$typeName]['jpg_count'];
                        $foundThumb = true;
                    } else {
                        ++$typeStats[$typeName]['missing_count'];
                    }
                }
            } else {
                foreach ($imageTypes as $type) {
                    ++$typeStats[$type['name']]['missing_count'];
                }
            }

            if ($foundOriginal) {
                ++$stats['has_original'];
            } elseif ($foundThumb) {
                ++$stats['thumbs_only'];
            } else {
                ++$stats['not_found'];
            }

            $expectedThumbsTotal += $expectedThumbsPerImage;

            if ($imageWebpCount >= $expectedThumbsPerImage) {
                ++$imagesWithAllWebp;
            }
        }

        foreach ($typeStats as $typeName => &$typeStat) {
            $total = $typeStat['webp_count'] + $typeStat['jpg_count'] + $typeStat['missing_count'];
            $typeStat['webp_percent'] = $total > 0
                ? round(($typeStat['webp_count'] / $total) * 100, 0)
                : 0;
        }
        unset($typeStat);

        $webpCoveragePercent = $expectedThumbsTotal > 0
            ? round(($webpThumbsCount / $expectedThumbsTotal) * 100, 1)
            : 0;

        $stats['sample_size'] = $sampleSize;
        $stats['expected_thumbs_per_image'] = $expectedThumbsPerImage;
        $stats['webp_thumbnails_sample'] = $webpThumbsCount;
        $stats['expected_thumbnails_sample'] = $expectedThumbsTotal;
        $stats['webp_coverage_percent'] = $webpCoveragePercent;
        $stats['images_with_all_webp'] = $imagesWithAllWebp;
        $stats['images_with_all_webp_percent'] = $sampleSize > 0
            ? round(($imagesWithAllWebp / $sampleSize) * 100, 1)
            : 0;
        $stats['type_stats'] = $typeStats;

        $totalImages = count($imageIds);
        if ($sampleSize > 0 && $sampleSize < $totalImages) {
            $ratio = $totalImages / $sampleSize;
            $stats['estimated_total_webp_thumbs'] = (int) ($webpThumbsCount * $ratio);
            $stats['estimated_total_expected_thumbs'] = $totalImages * $expectedThumbsPerImage;
        } else {
            $stats['estimated_total_webp_thumbs'] = $webpThumbsCount;
            $stats['estimated_total_expected_thumbs'] = $expectedThumbsTotal;
        }

        return $stats;
    }
}
