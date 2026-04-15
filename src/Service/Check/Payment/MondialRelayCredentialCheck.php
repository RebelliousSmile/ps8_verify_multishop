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

class MondialRelayCredentialCheck implements CheckInterface
{
    // Relay point mode credentials
    private const KEYS_RELAY = [
        'MONDIALRELAY_WEBSERVICE_KEY',
        'MONDIALRELAY_WEBSERVICE_ENSEIGNE',
    ];

    // Home delivery (API2) mode credentials
    private const KEYS_API2 = [
        'API2_LOGIN',
        'API2_PASSWORD',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getDomain(): string
    {
        return 'shipping';
    }

    public function getLabel(): string
    {
        return 'Mondial Relay — identifiants';
    }

    public function run(): CheckResult
    {
        // HOME_DELIVERY is stored globally (no shop scope)
        $homeDelivery = $this->connection->fetchOne(
            sprintf(
                'SELECT value FROM `%sconfiguration`
                 WHERE name = \'HOME_DELIVERY\'
                   AND (id_shop IS NULL OR id_shop = 0)
                   AND (id_shop_group IS NULL OR id_shop_group = 0)
                 LIMIT 1',
                $this->dbPrefix
            )
        );

        $isHomeDelivery = '1' === (string) $homeDelivery;
        $mode = $isHomeDelivery ? 'livraison à domicile (API2)' : 'point relais';
        $keysToCheck = $isHomeDelivery ? self::KEYS_API2 : self::KEYS_RELAY;

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT name, value FROM `%sconfiguration`
                 WHERE name IN (\'%s\')
                   AND (id_shop IS NULL OR id_shop = 0)
                   AND (id_shop_group IS NULL OR id_shop_group = 0)',
                $this->dbPrefix,
                implode("', '", array_map('addslashes', $keysToCheck))
            )
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['name']] = trim((string) $row['value']);
        }

        $missing = [];
        foreach ($keysToCheck as $key) {
            if (!isset($indexed[$key]) || '' === $indexed[$key]) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: sprintf(
                    'Identifiants Mondial Relay OK (mode %s).',
                    $mode
                ),
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('HOME_DELIVERY','MONDIALRELAY_WEBSERVICE_KEY','MONDIALRELAY_WEBSERVICE_ENSEIGNE','API2_LOGIN','API2_PASSWORD')\nAND (id_shop IS NULL OR id_shop = 0)\nAND (id_shop_group IS NULL OR id_shop_group = 0);",
            );
        }

        $issues = array_map(
            static fn (string $key): array => [
                'detail' => sprintf(
                    '%s manquant ou vide (mode %s) → Configurer via Modules > Mondial Relay > Configurer',
                    $key,
                    $mode
                ),
            ],
            $missing
        );

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf(
                '%d identifiant(s) Mondial Relay manquant(s) (mode %s).',
                count($missing),
                $mode
            ),
            issues: $issues,
            check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('HOME_DELIVERY','MONDIALRELAY_WEBSERVICE_KEY','MONDIALRELAY_WEBSERVICE_ENSEIGNE','API2_LOGIN','API2_PASSWORD')\nAND (id_shop IS NULL OR id_shop = 0)\nAND (id_shop_group IS NULL OR id_shop_group = 0);",
        );
    }
}
