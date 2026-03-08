<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use ScVerifyMultishop\Service\AnalyzerService;
use ScVerifyMultishop\Service\DiagnosticsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnostic controller for individual diagnostics
 */
class DiagnosticController extends FrameworkBundleAdminController
{
    private AnalyzerService $analyzerService;
    private DiagnosticsService $diagnosticsService;

    public function __construct(
        AnalyzerService $analyzerService,
        DiagnosticsService $diagnosticsService
    ) {
        $this->analyzerService = $analyzerService;
        $this->diagnosticsService = $diagnosticsService;
    }

    /**
     * List all available diagnostics
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function listAction(): Response
    {
        $diagnostics = [
            [
                'type' => 'shop_group',
                'icon' => 'group_work',
                'title' => 'Shop Group Sharing',
                'description' => 'Vérifier la configuration de partage entre les shop groups',
                'category' => 'configuration',
            ],
            [
                'type' => 'shops',
                'icon' => 'store',
                'title' => 'Shops',
                'description' => 'Analyser la configuration des boutiques',
                'category' => 'configuration',
            ],
            [
                'type' => 'payments',
                'icon' => 'payment',
                'title' => 'Payment Modules',
                'description' => 'Vérifier les modules de paiement et leurs associations',
                'category' => 'modules',
            ],
            [
                'type' => 'carriers',
                'icon' => 'local_shipping',
                'title' => 'Carriers',
                'description' => 'Analyser les transporteurs et leurs zones de livraison',
                'category' => 'shipping',
            ],
            [
                'type' => 'stocks',
                'icon' => 'inventory',
                'title' => 'Stocks',
                'description' => 'Vérifier la distribution des stocks par shop',
                'category' => 'catalog',
            ],
            [
                'type' => 'products',
                'icon' => 'inventory_2',
                'title' => 'Products',
                'description' => 'Analyser les associations produits-shops et traductions',
                'category' => 'catalog',
            ],
            [
                'type' => 'urls',
                'icon' => 'link',
                'title' => 'URLs',
                'description' => 'Vérifier les URLs des boutiques',
                'category' => 'seo',
            ],
            [
                'type' => 'images',
                'icon' => 'image',
                'title' => 'Images',
                'description' => 'Vérifier les images produits et leurs associations',
                'category' => 'catalog',
            ],
            [
                'type' => 'image_shop',
                'icon' => 'collections',
                'title' => 'Image Shop Associations',
                'description' => 'Vérifier les associations image_shop pour le multishop',
                'category' => 'catalog',
            ],
            [
                'type' => 'duplicate_stocks',
                'icon' => 'content_copy',
                'title' => 'Stock Duplication',
                'description' => 'Vérifier si les stocks doivent être dupliqués vers tous les shops',
                'category' => 'catalog',
            ],
            [
                'type' => 'null_quantities',
                'icon' => 'error_outline',
                'title' => 'NULL Quantities',
                'description' => 'Détecter les quantités NULL dans stock_available',
                'category' => 'catalog',
            ],
            [
                'type' => 'cms',
                'icon' => 'article',
                'title' => 'CMS Pages',
                'description' => 'Vérifier les pages CMS et catégories',
                'category' => 'content',
            ],
            [
                'type' => 'meta',
                'icon' => 'code',
                'title' => 'Meta Pages',
                'description' => 'Vérifier les pages meta et traductions SEO',
                'category' => 'seo',
            ],
            [
                'type' => 'module_config',
                'icon' => 'settings_applications',
                'title' => 'Configuration Modules',
                'description' => 'Détecter les NULL bloquants dans la configuration multishop des modules',
                'category' => 'configuration',
            ],
            [
                'type' => 'shop_override',
                'icon' => 'tune',
                'title' => 'Config Shop Override',
                'description' => 'Détecter les valeurs shop qui bloquent la propagation de la config globale',
                'category' => 'configuration',
            ],
        ];

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/diagnostics_list.html.twig',
            [
                'layoutTitle' => $this->trans('Diagnostics', 'Modules.Scverifymultishop.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'diagnostics' => $diagnostics,
                'current_tab' => 'diagnostics',
                'current_title' => 'Diagnostics',
            ]
        );
    }

    /**
     * Run a specific diagnostic
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function runAction(Request $request, string $type): Response
    {
        $result = $this->runDiagnostic($type);
        $acceptsJson = $request->headers->get('Accept') === 'application/json';

        if ($acceptsJson) {
            return new JsonResponse($result);
        }

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/diagnostic.html.twig',
            [
                'layoutTitle' => $this->trans('Diagnostic: %type%', 'Modules.Scverifymultishop.Admin', ['%type%' => $type]),
                'enableSidebar' => true,
                'help_link' => false,
                'type' => $type,
                'result' => $result,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runDiagnostic(string $type): array
    {
        return match ($type) {
            'shop_group' => $this->diagnosticsService->checkShopGroupSharing(),
            'shops' => $this->analyzerService->analyzeShops(),
            'payments' => $this->analyzerService->analyzePaymentModules(),
            'carriers' => $this->analyzerService->analyzeCarriers(),
            'stocks' => $this->analyzerService->analyzeStocks(),
            'products' => $this->analyzerService->analyzeProducts(),
            'urls' => $this->diagnosticsService->checkShopUrls(),
            'prices' => $this->diagnosticsService->checkPrices(),
            'images' => $this->diagnosticsService->checkImages(),
            'image_shop' => $this->diagnosticsService->checkImageShop(),
            'duplicate_stocks' => $this->runDuplicateStocksDiagnostic(),
            'null_quantities' => $this->runNullQuantitiesDiagnostic(),
            'currencies' => $this->diagnosticsService->checkCurrencies(),
            'cms' => $this->diagnosticsService->checkCmsPages(),
            'meta' => $this->diagnosticsService->checkMetaPages(),
            'module_config' => $this->diagnosticsService->checkModuleConfigurations(),
            'shop_override' => $this->diagnosticsService->checkShopOverrideConfigurations(),
            default => ['error' => 'Unknown diagnostic type', 'status' => 'error'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runDuplicateStocksDiagnostic(): array
    {
        $data = $this->analyzerService->analyzeStocks();

        $countGlobal = $data['count_global'] ?? 0;
        $countShop1 = $data['count_shop1'] ?? 0;
        $needsDuplication = $countShop1 > 0 && $countGlobal === 0;

        return [
            'count_shop1' => $countShop1,
            'count_global' => $countGlobal,
            'needs_duplication' => $needsDuplication,
            'status' => $needsDuplication ? 'warning' : 'ok',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runNullQuantitiesDiagnostic(): array
    {
        $data = $this->analyzerService->analyzeStocks();

        $nullQty = 0;
        foreach ($data['shop_distribution'] ?? [] as $shop) {
            $nullQty += (int) ($shop['null_quantities'] ?? 0);
        }

        return [
            'null_quantities' => $nullQty,
            'shop_distribution' => $data['shop_distribution'] ?? [],
            'status' => $nullQty > 0 ? 'error' : 'ok',
        ];
    }
}
