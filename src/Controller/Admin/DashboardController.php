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
use ScVerifyMultishop\Service\DiagnosticRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard controller with session-cached diagnostics
 */
class DashboardController extends FrameworkBundleAdminController
{
    private const SESSION_KEY = 'sc_verify_multishop_diagnostic';

    private DiagnosticRegistry $diagnosticRegistry;

    public function __construct(DiagnosticRegistry $diagnosticRegistry)
    {
        $this->diagnosticRegistry = $diagnosticRegistry;
    }

    /**
     * Dashboard index - shows cached diagnostic results or runs diagnostics if not cached
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function indexAction(Request $request): Response
    {
        $session = $request->getSession();

        $diagnostic = $session->get(self::SESSION_KEY);

        if ($diagnostic === null) {
            $diagnostic = $this->diagnosticRegistry->runAllDiagnostics();
            $session->set(self::SESSION_KEY, $diagnostic);
        }

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/dashboard.html.twig',
            [
                'layoutTitle' => $this->trans('Vérification Multishop', 'Modules.Scverifymultishop.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'diagnostic' => $diagnostic,
            ]
        );
    }

    /**
     * Refresh diagnostics - clears cache and re-runs all diagnostics
     *
     * @AdminSecurity(
     *     "is_granted('read', request.get('_legacy_controller'))",
     *     message="You do not have permission to access this.",
     *     redirectRoute="admin_dashboard"
     * )
     */
    public function refreshAction(Request $request): RedirectResponse
    {
        $session = $request->getSession();

        $diagnostic = $this->diagnosticRegistry->runAllDiagnostics();
        $session->set(self::SESSION_KEY, $diagnostic);

        $this->addFlash('success', $this->trans('Diagnostic actualisé', 'Modules.Scverifymultishop.Admin'));

        return $this->redirectToRoute('sc_verify_multishop_dashboard');
    }
}
