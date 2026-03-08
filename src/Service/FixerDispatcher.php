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
 * Dispatches fix operations to the appropriate fixer service
 */
class FixerDispatcher
{
    /** @var array<string, AbstractFixer> */
    private array $fixers = [];

    public function __construct(
        ShopGroupFixer $shopGroup,
        CarrierFixer $carrier,
        ProductFixer $product,
        StockFixer $stock,
        ContentFixer $content,
        ConfigFixer $config
    ) {
        foreach ([$shopGroup, $carrier, $product, $stock, $content, $config] as $fixer) {
            foreach ($fixer->getSupportedTypes() as $type) {
                $this->fixers[$type] = $fixer;
            }
        }
    }

    /**
     * Preview a fix (dry-run)
     *
     * @return array<string, mixed>
     */
    public function preview(string $type, array $options = []): array
    {
        $fixer = $this->fixers[$type] ?? null;
        if ($fixer === null) {
            return ['error' => 'Unknown fix type', 'success' => false];
        }

        if ($fixer instanceof ConfigFixer) {
            return $fixer->preview($type, $options);
        }

        return $fixer->preview($type);
    }

    /**
     * Apply a fix
     *
     * @return array<string, mixed>
     */
    public function apply(string $type, array $options = []): array
    {
        $fixer = $this->fixers[$type] ?? null;
        if ($fixer === null) {
            return ['error' => 'Unknown fix type', 'success' => false];
        }

        $result = $fixer instanceof ConfigFixer
            ? $fixer->apply($type, $options)
            : $fixer->apply($type);

        if ($result['success'] ?? false) {
            $this->clearPrestaShopCache();
            $result['cache_cleared'] = true;
        }

        return $result;
    }

    /**
     * Clear all PrestaShop caches (Smarty + Symfony)
     */
    private function clearPrestaShopCache(): void
    {
        try {
            if (class_exists(\Tools::class)) {
                \Tools::clearAllCache();
            }
        } catch (\Exception $e) {
            // Silently fail - cache clearing is not critical
        }
    }
}
