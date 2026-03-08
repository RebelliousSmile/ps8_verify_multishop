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
use ScVerifyMultishop\Service\FixerDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Fix controller for applying corrections
 */
class FixController extends FrameworkBundleAdminController
{
    private FixerDispatcher $fixerService;
    private CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(
        FixerDispatcher $fixerService,
        CsrfTokenManagerInterface $csrfTokenManager
    ) {
        $this->fixerService = $fixerService;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    /**
     * List all available fixes
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function listAction(): Response
    {
        $fixes = [
            [
                'type' => 'shop_group_sharing',
                'icon' => 'group_work',
                'title' => 'Shop Group Sharing',
                'description' => 'Activer le partage (customers, orders, stock) pour tous les shop groups',
                'category' => 'prerequisite',
                'severity' => 'critical',
            ],
            [
                'type' => 'carriers_payments',
                'icon' => 'local_shipping',
                'title' => 'Carriers & Payments',
                'description' => 'Corriger les associations transporteurs et modules de paiement',
                'category' => 'shipping',
                'severity' => 'high',
            ],
            [
                'type' => 'products',
                'icon' => 'inventory_2',
                'title' => 'Products',
                'description' => 'Dupliquer les données produits (traductions, associations, promotions, caractéristiques) vers tous les shops',
                'category' => 'catalog',
                'severity' => 'high',
            ],
            [
                'type' => 'stocks',
                'icon' => 'inventory',
                'title' => 'Stocks',
                'description' => 'Convertir id_shop 1→0 et corriger id_shop_group',
                'category' => 'catalog',
                'severity' => 'high',
            ],
            [
                'type' => 'duplicate_stocks',
                'icon' => 'content_copy',
                'title' => 'Duplicate Stocks',
                'description' => 'Dupliquer les stocks du shop 1 vers tous les autres shops',
                'category' => 'catalog',
                'severity' => 'medium',
            ],
            [
                'type' => 'null_quantities',
                'icon' => 'warning',
                'title' => 'NULL Quantities',
                'description' => 'Corriger les quantités NULL (smart copy depuis autres shops)',
                'category' => 'catalog',
                'severity' => 'medium',
            ],
            [
                'type' => 'image_shop',
                'icon' => 'image',
                'title' => 'Image Shop',
                'description' => 'Associer les images cover à tous les shops',
                'category' => 'catalog',
                'severity' => 'low',
            ],
            [
                'type' => 'cms',
                'icon' => 'article',
                'title' => 'CMS Pages',
                'description' => 'Corriger les catégories CMS pour tous les shops',
                'category' => 'content',
                'severity' => 'low',
            ],
            [
                'type' => 'meta',
                'icon' => 'code',
                'title' => 'Meta Pages',
                'description' => 'Corriger les traductions Meta pour tous les shops/langues',
                'category' => 'seo',
                'severity' => 'low',
            ],
            [
                'type' => 'module_config',
                'icon' => 'settings_applications',
                'title' => 'Configuration Modules',
                'description' => 'Corriger les NULL bloquants dans la configuration multishop des modules tiers',
                'category' => 'config',
                'severity' => 'high',
            ],
            [
                'type' => 'shop_override',
                'icon' => 'tune',
                'title' => 'Config Shop Override',
                'description' => 'Propager la valeur globale vers les shops qui la bloquent avec une valeur différente',
                'category' => 'config',
                'severity' => 'high',
            ],
        ];

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/fixes_list.html.twig',
            [
                'layoutTitle' => $this->trans('Corrections', 'Modules.Scverifymultishop.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'fixes' => $fixes,
                'current_tab' => 'fixes',
                'current_title' => 'Corrections',
            ]
        );
    }

    /**
     * Preview a fix (dry-run)
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function previewAction(Request $request, string $type): Response
    {
        $options = [];
        if ($request->query->has('module')) {
            $options['module'] = $request->query->get('module');
        }
        $result = $this->fixerService->preview($type, $options);
        $acceptsJson = $request->headers->get('Accept') === 'application/json';

        if ($acceptsJson) {
            return new JsonResponse($result);
        }

        $response = $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/fix_preview.html.twig',
            [
                'layoutTitle' => $this->trans('Preview Fix: %type%', 'Modules.Scverifymultishop.Admin', ['%type%' => $type]),
                'enableSidebar' => true,
                'help_link' => false,
                'type' => $type,
                'result' => $result,
                'csrfToken' => $this->csrfTokenManager->getToken('sc_verify_multishop_fix_' . $type)->getValue(),
            ]
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Apply a fix
     *
     * @AdminSecurity(
     *     "is_granted('update', request.get('_legacy_controller'))",
     *     message="You do not have permission to modify this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function applyAction(Request $request, string $type): Response
    {
        $token = $request->request->get('_token');
        $expectedToken = 'sc_verify_multishop_fix_' . $type;

        if (!$this->csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken($expectedToken, $token))) {
            $this->addFlash('error', $this->trans('Invalid CSRF token', 'Modules.Scverifymultishop.Admin'));

            return $this->redirectToRoute('sc_verify_multishop_dashboard');
        }

        $options = [];
        if ($request->request->has('module')) {
            $options['module'] = $request->request->get('module');
        }
        $result = $this->fixerService->apply($type, $options);

        // Always invalidate dashboard cache (success or failure) so dashboard shows fresh state
        $request->getSession()->remove('sc_verify_multishop_diagnostic');

        $acceptsJson = $request->headers->get('Accept') === 'application/json';

        if ($acceptsJson) {
            return new JsonResponse($result);
        }

        if ($result['success']) {
            $this->addFlash('success', $this->trans('Fix applied successfully', 'Modules.Scverifymultishop.Admin'));
        } else {
            $this->addFlash('error', $this->trans('Error applying fix: %error%', 'Modules.Scverifymultishop.Admin', ['%error%' => $result['error'] ?? 'Unknown']));
        }

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/fix_result.html.twig',
            [
                'layoutTitle' => $this->trans('Fix Result: %type%', 'Modules.Scverifymultishop.Admin', ['%type%' => $type]),
                'enableSidebar' => true,
                'help_link' => false,
                'type' => $type,
                'result' => $result,
            ]
        );
    }
}
