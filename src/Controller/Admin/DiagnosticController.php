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
use ScVerifyMultishop\Service\Check\CheckRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unified diagnostic controller with session-cached check results
 */
class DiagnosticController extends FrameworkBundleAdminController
{
    private const CACHE_KEY = 'sc_verify_check_results';
    private const CACHE_TIME_KEY = 'sc_verify_check_results_time';
    private const CACHE_TTL = 300;

    private CheckRegistry $checkRegistry;

    public function __construct(CheckRegistry $checkRegistry)
    {
        $this->checkRegistry = $checkRegistry;
    }

    /**
     * Main index action - runs all checks and renders the dashboard
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

        $forceRefresh = $request->query->get('refresh') === '1';
        $cachedResults = $session->get(self::CACHE_KEY);
        $cachedTime = (int) $session->get(self::CACHE_TIME_KEY, 0);
        $cacheExpired = (time() - $cachedTime) > self::CACHE_TTL;

        if ($forceRefresh || $cachedResults === null || $cacheExpired) {
            $results = $this->checkRegistry->runAll();
            $session->set(self::CACHE_KEY, $results);
            $session->set(self::CACHE_TIME_KEY, time());
            $lastRunAt = time();
        } else {
            $results = $cachedResults;
            $lastRunAt = $cachedTime;
        }

        $resultsByDomain = $this->groupByDomain($results);

        return $this->render(
            '@Modules/sc_verify_multishop/views/templates/admin/checks.html.twig',
            [
                'layoutTitle' => $this->trans('Vérification Multishop', 'Modules.Scverifymultishop.Admin'),
                'enableSidebar' => true,
                'help_link' => false,
                'results_by_domain' => $resultsByDomain,
                'last_run_at' => $lastRunAt,
            ]
        );
    }

    /**
     * Group an array of CheckResult objects by their domain property
     *
     * @param \ScVerifyMultishop\Service\Check\CheckResult[] $results
     * @return array<string, \ScVerifyMultishop\Service\Check\CheckResult[]>
     */
    private function groupByDomain(array $results): array
    {
        $domains = ['payment' => [], 'shipping' => [], 'products' => [], 'images' => []];

        foreach ($results as $result) {
            $domain = $result->domain;
            if (!array_key_exists($domain, $domains)) {
                $domains[$domain] = [];
            }
            $domains[$domain][] = $result;
        }

        // Remove empty domain buckets
        return array_filter($domains, fn (array $items) => count($items) > 0);
    }
}
