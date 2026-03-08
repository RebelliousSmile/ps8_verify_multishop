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
 * Central registry that runs all diagnostics and returns structured results
 * Results are meant to be cached in session for quick dashboard display
 */
class DiagnosticRegistry
{
    private AnalyzerService $analyzer;
    private DiagnosticsService $diagnostics;
    private ?bool $shareStockEnabled = null;

    public function __construct(
        AnalyzerService $analyzer,
        DiagnosticsService $diagnostics
    ) {
        $this->analyzer = $analyzer;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Check if share_stock is enabled in shop group
     */
    private function isShareStockEnabled(): bool
    {
        if ($this->shareStockEnabled === null) {
            $shopGroupData = $this->diagnostics->checkShopGroupSharing();
            $this->shareStockEnabled = (bool) ($shopGroupData['all_shares_enabled'] ?? false);
        }

        return $this->shareStockEnabled;
    }

    /**
     * Run all diagnostics and return structured results
     *
     * @return array{
     *     timestamp: int,
     *     summary: array{total: int, ok: int, warning: int, error: int},
     *     items: array<string, array{
     *         type: string,
     *         label: string,
     *         status: string,
     *         message: string,
     *         issue_count: int,
     *         fix_type: string|null,
     *         needs_fix: bool,
     *         details: array
     *     }>
     * }
     */
    public function runAllDiagnostics(): array
    {
        $items = [
            'shop_group' => $this->checkShopGroup(),
            'shops' => $this->checkShops(),
            'payments' => $this->checkPayments(),
            'carriers' => $this->checkCarriers(),
            'products' => $this->checkProducts(),
            'image_shop' => $this->checkImageShop(),
            'stocks' => $this->checkStocks(),
            'module_config' => $this->checkModuleConfig(),
            'shop_override' => $this->checkShopOverride(),
        ];

        // Merge shop_override status into module_config (single card)
        $statusOrder = ['ok' => 0, 'warning' => 1, 'error' => 2];
        foreach (['shop_override'] as $mergedKey) {
            $mergedStatus = $items[$mergedKey]['status'];
            if (($statusOrder[$mergedStatus] ?? 0) > ($statusOrder[$items['module_config']['status']] ?? 0)) {
                $items['module_config']['status'] = $mergedStatus;
            }
        }

        // Calculate summary — shop_override is rendered inside module_config card
        $summary = ['total' => 0, 'ok' => 0, 'warning' => 0, 'error' => 0];
        foreach ($items as $key => $item) {
            if (in_array($key, ['shop_override'], true)) {
                continue;
            }
            ++$summary['total'];
            ++$summary[$item['status']];
        }

        return [
            'timestamp' => time(),
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Build a diagnostic result array
     */
    private function buildResult(
        string $type,
        string $label,
        string $status,
        string $message,
        int $issueCount = 0,
        ?string $fixType = null,
        array $details = []
    ): array {
        return [
            'type' => $type,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'issue_count' => $issueCount,
            'fix_type' => $fixType,
            'needs_fix' => $status !== 'ok' && $fixType !== null && $issueCount > 0,
            'details' => $details,
        ];
    }

    private function checkShopGroup(): array
    {
        $data = $this->diagnostics->checkShopGroupSharing();

        $issueCount = 0;
        foreach ($data['issues'] ?? [] as $issue) {
            if (($issue['severity'] ?? '') !== 'success') {
                ++$issueCount;
            }
        }

        return $this->buildResult(
            type: 'shop_group',
            label: 'Shop Group Sharing',
            status: $data['status'] ?? 'ok',
            message: ($data['all_shares_enabled'] ?? false)
                ? 'Configuration de partage OK'
                : 'Partage à configurer',
            issueCount: $issueCount,
            fixType: 'shop_group_sharing',
            details: $data
        );
    }

    private function checkShops(): array
    {
        $data = $this->analyzer->analyzeShops();

        return $this->buildResult(
            type: 'shops',
            label: 'Boutiques',
            status: $data['status'] ?? 'ok',
            message: sprintf('%d boutique(s) configurée(s)', $data['total'] ?? 0),
            issueCount: 0,
            fixType: null,
            details: $data
        );
    }

    private function checkPayments(): array
    {
        $data = $this->analyzer->analyzePaymentModules();

        $totalModules = count($data['modules'] ?? []);
        $modulesOk = $data['modules_ok'] ?? 0;
        $issueCount = $totalModules - $modulesOk;
        if ($issueCount < 0) {
            $issueCount = 0;
        }

        $status = 'ok';
        if ($data['has_errors'] ?? false) {
            $status = 'error';
        } elseif ($data['has_warnings'] ?? false) {
            $status = 'warning';
        }

        return $this->buildResult(
            type: 'payments',
            label: 'Modules de paiement',
            status: $status,
            message: $issueCount > 0
                ? sprintf('%d module(s) à configurer', $issueCount)
                : 'Tous les modules OK',
            issueCount: $issueCount,
            fixType: 'carriers_payments',
            details: $data
        );
    }

    private function checkCarriers(): array
    {
        $data = $this->analyzer->analyzeCarriers();

        $issueCount = ($data['active'] ?? 0) - ($data['carriers_ok'] ?? 0);
        if ($issueCount < 0) {
            $issueCount = 0;
        }

        return $this->buildResult(
            type: 'carriers',
            label: 'Transporteurs',
            status: $data['status'] ?? 'ok',
            message: $issueCount > 0
                ? sprintf('%d transporteur(s) à configurer', $issueCount)
                : 'Tous les transporteurs OK',
            issueCount: $issueCount,
            fixType: 'carriers_payments',
            details: $data
        );
    }

    private function checkProducts(): array
    {
        $data = $this->analyzer->analyzeProducts();

        $totalProducts = $data['total_products'] ?? 0;
        $totalMissing = $data['total_missing'] ?? 0;

        $messages = [];
        $hasErrors = false;

        foreach ($data['steps'] ?? [] as $step) {
            $missing = $step['missing'] ?? 0;
            if ($missing > 0) {
                $messages[] = sprintf('%s: %d manquant(s)', $step['label'] ?? $step['table'], $missing);
                if (($step['status'] ?? '') === 'error') {
                    $hasErrors = true;
                }
            }
        }

        $hasIssues = $totalMissing > 0;

        return $this->buildResult(
            type: 'products',
            label: 'Produits',
            status: $hasErrors ? 'error' : ($hasIssues ? 'warning' : 'ok'),
            message: $hasIssues
                ? implode(', ', array_slice($messages, 0, 3))
                : sprintf('%d produit(s) OK dans toutes les boutiques', $totalProducts),
            issueCount: $totalMissing,
            fixType: $hasIssues ? 'products' : null,
            details: $data
        );
    }

    private function checkStocks(): array
    {
        $data = $this->analyzer->analyzeStocks();

        $shareStock = (bool) ($data['share_stock'] ?? false);
        $countShop1 = $data['count_shop1'] ?? 0;
        $countGlobal = $data['count_global'] ?? 0;

        $nullQty = 0;
        foreach ($data['shop_distribution'] ?? [] as $shop) {
            $nullQty += (int) ($shop['null_quantities'] ?? 0);
        }

        $status = $data['diagnostic_type'] ?? 'ok';

        if ($nullQty > 0) {
            $status = 'error';
        }

        $fixType = null;
        $issueCount = 0;

        if ($nullQty > 0) {
            $fixType = 'null_quantities';
            $issueCount += $nullQty;
        }

        if ($shareStock && $countShop1 > 0) {
            if ($fixType === null) {
                $fixType = 'stocks';
            }
            $issueCount += $countShop1;
        }

        $messages = [];
        if ($nullQty > 0) {
            $messages[] = sprintf('%d quantités NULL', $nullQty);
        }
        if ($shareStock && $countShop1 > 0) {
            $messages[] = sprintf('%d stocks à migrer vers global', $countShop1);
        }

        if (count($messages) > 0) {
            $dashboardMessage = implode(', ', $messages);
        } else {
            $dashboardMessage = $shareStock
                ? sprintf('%d entrée(s) en mode partagé (global)', $countGlobal)
                : sprintf('%d entrée(s) OK', $data['count_total'] ?? 0);
        }

        return $this->buildResult(
            type: 'stocks',
            label: 'Stocks',
            status: $status,
            message: $dashboardMessage,
            issueCount: $issueCount,
            fixType: $fixType,
            details: array_merge($data, [
                'null_quantities' => $nullQty,
                'share_stock_enabled' => $shareStock,
            ])
        );
    }

    private function checkImageShop(): array
    {
        $data = $this->diagnostics->checkImageShop();

        $missingCount = $data['missing_associations'] ?? 0;
        $orphanCount = $data['orphan_entries'] ?? 0;
        $issueCount = $missingCount + $orphanCount;

        $messages = [];
        if ($missingCount > 0) {
            $messages[] = sprintf('%d image(s) sans association shop', $missingCount);
        }
        if ($orphanCount > 0) {
            $messages[] = sprintf('%d orpheline(s)', $orphanCount);
        }

        return $this->buildResult(
            type: 'image_shop',
            label: 'Images Shop',
            status: $data['status'] ?? ($issueCount > 0 ? 'warning' : 'ok'),
            message: count($messages) > 0
                ? implode(', ', $messages)
                : 'Toutes les images associées',
            issueCount: $issueCount,
            fixType: 'image_shop',
            details: $data
        );
    }

    private function checkModuleConfig(): array
    {
        $data = $this->diagnostics->checkModuleConfigurations();
        $issueCount = $data['total_issues'] ?? 0;
        $moduleCount = count($data['modules_with_issues'] ?? []);

        return $this->buildResult(
            type: 'module_config',
            label: 'Configuration Modules',
            status: $data['status'] ?? 'ok',
            message: $moduleCount > 0
                ? sprintf('%d module(s) avec config incohérente', $moduleCount)
                : 'Configuration modules OK',
            issueCount: $issueCount,
            fixType: 'module_config',
            details: $data
        );
    }

    private function checkShopOverride(): array
    {
        $data = $this->diagnostics->checkShopOverrideConfigurations();
        $issueCount = $data['total_issues'] ?? 0;
        $moduleCount = count($data['modules_with_issues'] ?? []);

        return $this->buildResult(
            type: 'shop_override',
            label: 'Config Shop Override',
            status: $data['status'] ?? 'ok',
            message: $moduleCount > 0
                ? sprintf('%d module(s) avec valeur shop bloquant le global', $moduleCount)
                : 'Propagation config globale OK',
            issueCount: $issueCount,
            fixType: 'shop_override',
            details: $data
        );
    }
}
