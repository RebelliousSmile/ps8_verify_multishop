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

class ColissimoCredentialCheck implements CheckInterface
{
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
        return 'Colissimo — identifiants';
    }

    public function run(): CheckResult
    {
        $shops = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id_shop, name FROM `%sshop` WHERE active = 1',
                $this->dbPrefix
            )
        );

        if (empty($shops)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Aucune boutique active trouvée.',
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('COLISSIMO_CONNEXION_KEY','COLISSIMO_ACCOUNT_LOGIN','COLISSIMO_ACCOUNT_PASSWORD','COLISSIMO_ACCOUNT_KEY')\nORDER BY id_shop, name;",
            );
        }

        $shopsWithIssues = [];

        foreach ($shops as $shop) {
            $idShop = (int) $shop['id_shop'];
            $shopName = (string) $shop['name'];

            $connexionKey = $this->getConfigValue('COLISSIMO_CONNEXION_KEY', $idShop);

            if ('1' === $connexionKey) {
                // Mode clé API : vérifie COLISSIMO_ACCOUNT_KEY uniquement
                $keyValue = $this->getConfigValue('COLISSIMO_ACCOUNT_KEY', $idShop);
                if ('' === $keyValue) {
                    $shopsWithIssues[] = sprintf(
                        '%s (id=%d) : COLISSIMO_ACCOUNT_KEY manquant (mode clé API)',
                        $shopName,
                        $idShop
                    );
                }
            } else {
                // Mode login/mot de passe : vérifie COLISSIMO_ACCOUNT_LOGIN + COLISSIMO_ACCOUNT_PASSWORD
                $login = $this->getConfigValue('COLISSIMO_ACCOUNT_LOGIN', $idShop);
                $password = $this->getConfigValue('COLISSIMO_ACCOUNT_PASSWORD', $idShop);
                $missing = [];
                if ('' === $login) {
                    $missing[] = 'COLISSIMO_ACCOUNT_LOGIN';
                }
                if ('' === $password) {
                    $missing[] = 'COLISSIMO_ACCOUNT_PASSWORD';
                }
                if (!empty($missing)) {
                    $shopsWithIssues[] = sprintf(
                        '%s (id=%d) : %s manquant(s) (mode login/mot de passe)',
                        $shopName,
                        $idShop,
                        implode(', ', $missing)
                    );
                }
            }
        }

        if (empty($shopsWithIssues)) {
            return new CheckResult(
                domain: $this->getDomain(),
                label: $this->getLabel(),
                status: 'ok',
                message: 'Identifiants Colissimo OK sur toutes les boutiques.',
                check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('COLISSIMO_CONNEXION_KEY','COLISSIMO_ACCOUNT_LOGIN','COLISSIMO_ACCOUNT_PASSWORD','COLISSIMO_ACCOUNT_KEY')\nORDER BY id_shop, name;",
            );
        }

        $issues = array_map(
            static fn (string $detail): array => [
                'detail' => $detail . ' → Configurer via Modules > Colissimo > Configurer',
            ],
            $shopsWithIssues
        );

        return new CheckResult(
            domain: $this->getDomain(),
            label: $this->getLabel(),
            status: 'error',
            message: sprintf(
                '%d boutique(s) avec identifiants Colissimo manquants.',
                count($shopsWithIssues)
            ),
            issues: $issues,
            check_query: "SELECT name, value, id_shop, id_shop_group\nFROM {prefix}configuration\nWHERE name IN ('COLISSIMO_CONNEXION_KEY','COLISSIMO_ACCOUNT_LOGIN','COLISSIMO_ACCOUNT_PASSWORD','COLISSIMO_ACCOUNT_KEY')\nORDER BY id_shop, name;",
        );
    }

    /**
     * Reads a configuration value scoped to a shop, falling back to global scope.
     * Returns empty string when absent or null.
     */
    private function getConfigValue(string $name, int $idShop): string
    {
        $row = $this->connection->fetchOne(
            sprintf(
                'SELECT value FROM `%sconfiguration`
                 WHERE name = :name
                   AND (id_shop = :id_shop OR id_shop IS NULL OR id_shop = 0)
                 ORDER BY id_shop DESC
                 LIMIT 1',
                $this->dbPrefix
            ),
            ['name' => $name, 'id_shop' => $idShop]
        );

        return '' === trim((string) $row) ? '' : trim((string) $row);
    }
}
