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

/**
 * Fixer for content-related data: image_shop, cms, meta
 */
class ContentFixer extends AbstractFixer
{
    public function getSupportedTypes(): array
    {
        return ['image_shop', 'cms', 'meta'];
    }

    public function preview(string $type): array
    {
        return match ($type) {
            'image_shop' => $this->previewImageShop(),
            'cms' => $this->previewCms(),
            'meta' => $this->previewMeta(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type): array
    {
        return match ($type) {
            'image_shop' => $this->fixImageShop(),
            'cms' => $this->fixCms(),
            'meta' => $this->fixMeta(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    private function previewImageShop(): array
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

        return [
            'success' => true,
            'type' => 'image_shop',
            'description' => 'Association de toutes les images produits à tous les shops',
            'changes' => [
                'total_images' => $totalImages,
                'missing_associations' => $missingCount,
                'total_shops' => $totalShops,
            ],
        ];
    }

    private function previewCms(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            "SELECT id_shop FROM {$this->prefix}shop ORDER BY id_shop"
        );
        $languages = $this->connection->fetchAllAssociative(
            "SELECT id_lang FROM {$this->prefix}lang WHERE active = 1"
        );

        $missingShopAssoc = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}cms_category c
            CROSS JOIN {$this->prefix}shop s
            WHERE NOT EXISTS (
                SELECT 1 FROM {$this->prefix}cms_category_shop cs
                WHERE cs.id_cms_category = c.id_cms_category AND cs.id_shop = s.id_shop
            )
        ");

        return [
            'success' => true,
            'type' => 'cms',
            'description' => 'Correction des catégories CMS pour tous les shops',
            'changes' => [
                'missing_shop_associations' => $missingShopAssoc,
                'total_shops' => count($shops),
                'total_languages' => count($languages),
            ],
        ];
    }

    private function previewMeta(): array
    {
        $totalShops = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}shop WHERE active = 1"
        );
        $totalLangs = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$this->prefix}lang WHERE active = 1"
        );

        $missingCount = (int) $this->connection->fetchOne("
            SELECT COUNT(*)
            FROM {$this->prefix}meta m
            CROSS JOIN {$this->prefix}shop s
            CROSS JOIN {$this->prefix}lang l
            WHERE s.active = 1 AND l.active = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$this->prefix}meta_lang ml
                WHERE ml.id_meta = m.id_meta AND ml.id_shop = s.id_shop AND ml.id_lang = l.id_lang
            )
        ");

        return [
            'success' => true,
            'type' => 'meta',
            'description' => 'Correction des traductions Meta pour tous les shops/langues',
            'changes' => [
                'missing_translations' => $missingCount,
                'total_shops' => $totalShops,
                'total_languages' => $totalLangs,
            ],
        ];
    }

    private function fixImageShop(): array
    {
        try {
            $sourceShopId = (int) $this->connection->fetchOne(
                "SELECT MIN(id_shop) FROM {$this->prefix}shop WHERE active = 1"
            );

            // Step 1: Clean orphan image_shop entries for inactive products
            $this->connection->executeStatement("
                DELETE ish FROM {$this->prefix}image_shop ish
                INNER JOIN {$this->prefix}image i ON ish.id_image = i.id_image
                INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
                WHERE p.active = 0
            ");
            $cleaned = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');

            // Step 2: Insert missing associations for active products
            $this->connection->executeStatement("
                INSERT IGNORE INTO {$this->prefix}image_shop (id_image, id_shop, cover, id_product)
                SELECT
                    i.id_image,
                    s.id_shop,
                    COALESCE(ish_source.cover, i.cover),
                    i.id_product
                FROM {$this->prefix}image i
                CROSS JOIN {$this->prefix}shop s
                INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
                LEFT JOIN {$this->prefix}image_shop ish_source
                    ON i.id_image = ish_source.id_image
                    AND ish_source.id_shop = ?
                WHERE p.active = 1
                AND s.active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->prefix}image_shop ish
                    WHERE ish.id_image = i.id_image
                    AND ish.id_shop = s.id_shop
                )
            ", [$sourceShopId]);

            $inserted = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');

            return [
                'success' => true,
                'type' => 'image_shop',
                'cleaned_orphans' => $cleaned,
                'inserted' => $inserted,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fixCms(): array
    {
        try {
            $this->connection->executeStatement("
                INSERT INTO {$this->prefix}cms_category_shop (id_cms_category, id_shop)
                SELECT c.id_cms_category, s.id_shop
                FROM {$this->prefix}cms_category c
                CROSS JOIN {$this->prefix}shop s
                WHERE NOT EXISTS (
                    SELECT 1 FROM {$this->prefix}cms_category_shop cs
                    WHERE cs.id_cms_category = c.id_cms_category AND cs.id_shop = s.id_shop
                )
            ");
            $shopAssocInserted = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');

            $this->connection->executeStatement("
                INSERT INTO {$this->prefix}cms_category_lang
                (id_cms_category, id_shop, id_lang, name, link_rewrite, description, meta_title, meta_keywords, meta_description)
                SELECT 1, s.id_shop, l.id_lang, 'Accueil', 'accueil', '', '', '', ''
                FROM {$this->prefix}shop s
                CROSS JOIN {$this->prefix}lang l
                WHERE l.active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->prefix}cms_category_lang ccl
                    WHERE ccl.id_cms_category = 1 AND ccl.id_lang = l.id_lang AND ccl.id_shop = s.id_shop
                )
            ");
            $langInserted = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');

            return [
                'success' => true,
                'type' => 'cms',
                'shop_associations' => $shopAssocInserted,
                'translations' => $langInserted,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fixMeta(): array
    {
        try {
            $this->connection->executeStatement("
                INSERT INTO {$this->prefix}meta_lang (id_meta, id_shop, id_lang, title, description, keywords, url_rewrite)
                SELECT
                    m.id_meta,
                    s.id_shop,
                    l.id_lang,
                    COALESCE(existing.title, ''),
                    COALESCE(existing.description, ''),
                    COALESCE(existing.keywords, ''),
                    COALESCE(existing.url_rewrite, m.page)
                FROM {$this->prefix}meta m
                CROSS JOIN {$this->prefix}shop s
                CROSS JOIN {$this->prefix}lang l
                LEFT JOIN (
                    SELECT id_meta, id_lang, title, description, keywords, url_rewrite
                    FROM {$this->prefix}meta_lang
                    WHERE id_shop = 1
                ) AS existing ON existing.id_meta = m.id_meta AND existing.id_lang = l.id_lang
                WHERE s.active = 1 AND l.active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->prefix}meta_lang ml
                    WHERE ml.id_meta = m.id_meta AND ml.id_shop = s.id_shop AND ml.id_lang = l.id_lang
                )
            ");

            $inserted = (int) $this->connection->fetchOne('SELECT ROW_COUNT()');

            return [
                'success' => true,
                'type' => 'meta',
                'inserted' => $inserted,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
