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

class StripeCredentialCheck implements CheckInterface
{
    private const LIVE_KEYS = [
        'STRIPE_KEY',
        'STRIPE_PUBLISHABLE',
    ];

    private const TEST_KEYS = [
        'STRIPE_TEST_KEY',
        'STRIPE_TEST_PUBLISHABLE',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getDomain(): string
    {
        return 'payment';
    }

    public function getLabel(): string
    {
        return 'Stripe — identifiants par boutique';
    }

    public function run(): CheckResult
    {
        $shops = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id_shop, name, id_shop_group FROM `%sshop` WHERE active = 1',
                $this->dbPrefix
            )
        );

        if (empty($shops)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'warning',
                message: 'Aucune boutique active',
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('STRIPE_KEY','STRIPE_PUBLISHABLE','STRIPE_TEST_KEY','STRIPE_TEST_PUBLISHABLE','STRIPE_MODE')\nORDER BY id_shop, name;",
            );
        }

        $issues = [];

        foreach ($shops as $shop) {
            $idShop = (int) $shop['id_shop'];
            $idShopGroup = (int) $shop['id_shop_group'];
            $shopName = (string) $shop['name'];

            $mode = $this->getShopConfigValue($idShop, $idShopGroup, 'STRIPE_MODE');

            $isTestMode = '1' === $mode;
            $requiredKeys = $isTestMode ? self::TEST_KEYS : self::LIVE_KEYS;
            $modeLabel = $isTestMode ? 'mode test' : 'mode live';

            foreach ($requiredKeys as $key) {
                $value = $this->getShopConfigValue($idShop, $idShopGroup, $key);
                if ('' === $value) {
                    $issues[] = [
                        'detail' => sprintf(
                            'Stripe [%s] — %s manquant ou vide pour boutique "%s" (id=%d) → Configurer via Modules > Stripe > Configurer',
                            $modeLabel,
                            $key,
                            $shopName,
                            $idShop
                        ),
                    ];
                }
            }
        }

        if (empty($issues)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: sprintf(
                    'Identifiants Stripe OK sur %d boutique(s) — aucun problème détecté.',
                    count($shops)
                ),
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('STRIPE_KEY','STRIPE_PUBLISHABLE','STRIPE_TEST_KEY','STRIPE_TEST_PUBLISHABLE','STRIPE_MODE')\nORDER BY id_shop, name;",
            );
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf(
                '%d identifiant(s) Stripe manquant(s) ou vide(s) sur %d boutique(s).',
                count($issues),
                count($shops)
            ),
            issues: $issues,
            check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('STRIPE_KEY','STRIPE_PUBLISHABLE','STRIPE_TEST_KEY','STRIPE_TEST_PUBLISHABLE','STRIPE_MODE')\nORDER BY id_shop, name;",
        );
    }

    /**
     * Reads a configuration value replicating PrestaShop Configuration::get() priority:
     * 1. shop-scoped (id_shop = $idShop)
     * 2. group-scoped (id_shop_group = $idShopGroup, id_shop IS NULL)
     * 3. global (id_shop IS NULL, id_shop_group IS NULL)
     *
     * Returns empty string when absent or null.
     */
    private function getShopConfigValue(int $idShop, int $idShopGroup, string $key): string
    {
        $row = $this->connection->fetchOne(
            sprintf(
                'SELECT value FROM `%sconfiguration`
                 WHERE name = :name
                   AND (
                     (id_shop = :id_shop)
                     OR (id_shop_group = :id_shop_group AND (id_shop IS NULL OR id_shop = 0))
                     OR ((id_shop IS NULL OR id_shop = 0) AND (id_shop_group IS NULL OR id_shop_group = 0))
                   )
                 ORDER BY
                   CASE
                     WHEN id_shop = :id_shop THEN 0
                     WHEN id_shop_group = :id_shop_group THEN 1
                     ELSE 2
                   END
                 LIMIT 1',
                $this->dbPrefix
            ),
            ['name' => $key, 'id_shop' => $idShop, 'id_shop_group' => $idShopGroup]
        );

        return '' === trim((string) $row) ? '' : trim((string) $row);
    }
}
