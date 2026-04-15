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

class PaypalCredentialCheck implements CheckInterface
{
    private const KEYS_LIVE = [
        'PAYPAL_EC_CLIENTID_LIVE',
        'PAYPAL_EC_SECRET_LIVE',
        'PAYPAL_EC_MERCHANT_ID_LIVE',
    ];

    private const KEYS_SANDBOX = [
        'PAYPAL_EC_CLIENTID_SANDBOX',
        'PAYPAL_EC_SECRET_SANDBOX',
        'PAYPAL_EC_MERCHANT_ID_SANDBOX',
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
        return 'PayPal — identifiants';
    }

    public function run(): CheckResult
    {
        $isSandbox = $this->isSandboxMode();
        $mode = $isSandbox ? 'sandbox' : 'live';
        $keys = $isSandbox ? self::KEYS_SANDBOX : self::KEYS_LIVE;

        $quotedKeys = implode("', '", array_map('addslashes', $keys));

        $sql = sprintf(
            'SELECT name, value FROM `%sconfiguration`
             WHERE name IN (\'%s\')
               AND (id_shop IS NULL OR id_shop = 0)
               AND (id_shop_group IS NULL OR id_shop_group = 0)',
            $this->dbPrefix,
            $quotedKeys
        );

        $rows = $this->connection->fetchAllAssociative($sql);

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['name']] = $row['value'];
        }

        $issues = [];

        foreach ($keys as $key) {
            if (!isset($indexed[$key]) || '' === trim((string) $indexed[$key])) {
                $issues[] = [
                    'detail' => sprintf(
                        '%s manquant ou vide (mode %s) → Configurer via Modules > PayPal > Configurer',
                        $key,
                        $mode
                    ),
                ];
            }
        }

        if (empty($issues)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: sprintf(
                    '%d identifiant(s) PayPal vérifiés en mode %s — aucun problème détecté.',
                    count($keys),
                    $mode
                ),
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('PAYPAL_EC_CLIENTID_LIVE','PAYPAL_EC_SECRET_LIVE','PAYPAL_EC_MERCHANT_ID_LIVE','PAYPAL_EC_CLIENTID_SANDBOX','PAYPAL_EC_SECRET_SANDBOX','PAYPAL_EC_MERCHANT_ID_SANDBOX','PAYPAL_SANDBOX')\nAND (id_shop IS NULL OR id_shop = 0)\nAND (id_shop_group IS NULL OR id_shop_group = 0);",
            );
        }

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf(
                '%d identifiant(s) PayPal manquant(s) ou vide(s) (mode %s).',
                count($issues),
                $mode
            ),
            issues: $issues,
            check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('PAYPAL_EC_CLIENTID_LIVE','PAYPAL_EC_SECRET_LIVE','PAYPAL_EC_MERCHANT_ID_LIVE','PAYPAL_EC_CLIENTID_SANDBOX','PAYPAL_EC_SECRET_SANDBOX','PAYPAL_EC_MERCHANT_ID_SANDBOX','PAYPAL_SANDBOX')\nAND (id_shop IS NULL OR id_shop = 0)\nAND (id_shop_group IS NULL OR id_shop_group = 0);",
        );
    }

    private function isSandboxMode(): bool
    {
        $sql = sprintf(
            'SELECT value FROM `%sconfiguration`
             WHERE name = \'PAYPAL_SANDBOX\'
               AND (id_shop IS NULL OR id_shop = 0)
               AND (id_shop_group IS NULL OR id_shop_group = 0)
             LIMIT 1',
            $this->dbPrefix
        );

        $value = $this->connection->fetchOne($sql);

        return '1' === (string) $value;
    }
}
