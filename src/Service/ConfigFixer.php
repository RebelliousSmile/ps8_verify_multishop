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
 * Generic fixer for module configuration issues in multishop context
 *
 * Detects and fixes "blocking NULLs": configuration entries where
 * shop-level or shop_group-level values are NULL/empty while a
 * valid global value exists. These NULLs override PrestaShop's
 * inheritance chain (global -> shop_group -> shop).
 *
 * Also detects and fixes "shop overrides": configuration entries where
 * shop-level or shop_group-level values are non-null but differ from
 * the global value, causing the global value to be ignored.
 */
class ConfigFixer extends AbstractFixer
{
    /** @var array<array{id_configuration: int, name: string, value: string|null, id_shop: int|null, id_shop_group: int|null, module_name: string}>|null */
    private ?array $allModuleConfigsCache = null;

    public function getSupportedTypes(): array
    {
        return ['module_config', 'shop_override'];
    }

    public function preview(string $type, array $options = []): array
    {
        return match ($type) {
            'module_config' => $this->previewModuleConfig($options),
            'shop_override' => $this->previewShopOverride($options),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type, array $options = []): array
    {
        return match ($type) {
            'module_config' => $this->applyModuleConfig($options),
            'shop_override' => $this->applyShopOverride($options),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    private function previewModuleConfig(array $options): array
    {
        $allBlockingNulls = $this->loadAllBlockingNulls();
        $modulesWithIssues = $this->groupByModule($allBlockingNulls);

        if (empty($options['module'])) {
            if (count($modulesWithIssues) === 1) {
                $options['module'] = $modulesWithIssues[0]['name'];
            } else {
                return [
                    'success' => true,
                    'type' => 'module_config',
                    'description' => 'Configuration modules multishop - NULL bloquants',
                    'modules' => $modulesWithIssues,
                    'selected_module' => null,
                    'changes' => [
                        'modules_with_issues' => count($modulesWithIssues),
                        'total_issues' => array_sum(array_column($modulesWithIssues, 'issue_count')),
                    ],
                ];
            }
        }

        $moduleName = $options['module'];
        if (!$this->isValidModuleName($moduleName)) {
            return ['success' => false, 'error' => 'Nom de module invalide'];
        }

        $moduleNulls = array_filter(
            $allBlockingNulls,
            fn (array $n) => $n['module'] === $moduleName
        );

        return $this->buildModulePreview($moduleName, $moduleNulls, $modulesWithIssues);
    }

    private function applyModuleConfig(array $options): array
    {
        if (empty($options['module'])) {
            return ['success' => false, 'error' => 'Aucun module sélectionné'];
        }

        $moduleName = $options['module'];
        if (!$this->isValidModuleName($moduleName)) {
            return ['success' => false, 'error' => 'Nom de module invalide'];
        }

        return $this->fixModule($moduleName);
    }

    /**
     * Validate module name: format check (prevents SQL injection) + optional existence check.
     *
     * When the all-configs cache is populated (preview path), validates the module
     * name against the loaded data — no extra DB query needed.
     * When the cache is empty (apply path), the regex alone is sufficient since
     * queries use parameterized placeholders and an unknown module just yields
     * an empty result.
     */
    private function isValidModuleName(string $name): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $name)) {
            return false;
        }

        if ($this->allModuleConfigsCache !== null) {
            foreach ($this->allModuleConfigsCache as $config) {
                if ($config['module_name'] === $name) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Load ALL config entries for active non-PS modules (shared query)
     *
     * @return array<array{id_configuration: int, name: string, value: string|null, id_shop: int|null, id_shop_group: int|null, module_name: string}>
     */
    private function loadAllModuleConfigs(): array
    {
        if ($this->allModuleConfigsCache === null) {
            $this->allModuleConfigsCache = $this->connection->fetchAllAssociative("
                SELECT c.id_configuration, c.name, c.value, c.id_shop, c.id_shop_group, m.name AS module_name
                FROM {$this->prefix}configuration c
                INNER JOIN {$this->prefix}module m
                    ON c.name LIKE CONCAT(UPPER(m.name), '\\_%')
                WHERE m.name NOT LIKE 'ps\\_%'
                  AND m.active = 1
                ORDER BY m.name, c.name, c.id_shop_group, c.id_shop
            ");
        }

        return $this->allModuleConfigsCache;
    }

    /**
     * Load ALL blocking NULLs for all non-PS modules in a single query
     *
     * @return array<array{module: string, id_configuration: int, name: string, id_shop: int|null, id_shop_group: int|null, global_value: string, raw_global_value: string}>
     */
    private function loadAllBlockingNulls(): array
    {
        return $this->detectBlockingNulls($this->loadAllModuleConfigs());
    }

    /**
     * Load blocking NULLs for a single module (used by apply for precision)
     *
     * @return array<array{module: string, id_configuration: int, name: string, id_shop: int|null, id_shop_group: int|null, global_value: string, raw_global_value: string}>
     */
    private function loadModuleBlockingNulls(string $moduleName): array
    {
        $prefix = strtoupper($moduleName) . '_%';

        $configs = $this->connection->fetchAllAssociative(
            "SELECT id_configuration, name, value, id_shop, id_shop_group
             FROM {$this->prefix}configuration
             WHERE name LIKE ?
             ORDER BY name, id_shop_group, id_shop",
            [$prefix]
        );

        foreach ($configs as &$row) {
            $row['module_name'] = $moduleName;
        }
        unset($row);

        return $this->detectBlockingNulls($configs);
    }

    /**
     * Detect blocking NULLs from a set of configuration rows
     *
     * A "blocking NULL" is a config entry at shop or shop_group level
     * where value IS NULL or empty, but a non-null global value exists.
     *
     * @return array<array{module: string, id_configuration: int, name: string, id_shop: int|null, id_shop_group: int|null, global_value: string, raw_global_value: string}>
     */
    private function detectBlockingNulls(array $configs): array
    {
        $byKey = [];
        foreach ($configs as $row) {
            $byKey[$row['name']][] = $row;
        }

        $blockingNulls = [];

        foreach ($byKey as $entries) {
            $globalValue = null;
            foreach ($entries as $entry) {
                if ($this->isGlobalScope($entry) && $entry['value'] !== null && $entry['value'] !== '') {
                    $globalValue = $entry['value'];
                    break;
                }
            }

            if ($globalValue === null) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($this->isGlobalScope($entry)) {
                    continue;
                }

                if ($entry['value'] === null || $entry['value'] === '') {
                    $blockingNulls[] = [
                        'module' => $entry['module_name'],
                        'id_configuration' => (int) $entry['id_configuration'],
                        'name' => $entry['name'],
                        'id_shop' => $entry['id_shop'] !== null ? (int) $entry['id_shop'] : null,
                        'id_shop_group' => $entry['id_shop_group'] !== null ? (int) $entry['id_shop_group'] : null,
                        'global_value' => $this->maskSensitiveValue($entry['name'], $globalValue),
                        'raw_global_value' => $globalValue,
                    ];
                }
            }
        }

        return $blockingNulls;
    }

    /**
     * Check if a configuration entry is at global scope
     */
    private function isGlobalScope(array $entry): bool
    {
        return ((int) ($entry['id_shop'] ?? 0) === 0)
            && ((int) ($entry['id_shop_group'] ?? 0) === 0);
    }

    /**
     * Group blocking NULLs by module for summary display
     *
     * @return array<array{name: string, issue_count: int}>
     */
    private function groupByModule(array $blockingNulls): array
    {
        $byModule = [];
        foreach ($blockingNulls as $null) {
            $byModule[$null['module']] = ($byModule[$null['module']] ?? 0) + 1;
        }

        $result = [];
        foreach ($byModule as $name => $count) {
            $result[] = ['name' => $name, 'issue_count' => $count];
        }

        return $result;
    }

    /**
     * Build preview response for a specific module
     */
    private function buildModulePreview(string $moduleName, array $moduleNulls, array $modulesWithIssues): array
    {
        $keyGroups = [];
        foreach ($moduleNulls as $null) {
            $keyGroups[$null['name']][] = [
                'id_shop' => $null['id_shop'],
                'id_shop_group' => $null['id_shop_group'],
                'will_set_to' => $null['global_value'],
            ];
        }

        return [
            'success' => true,
            'type' => 'module_config',
            'description' => sprintf('Corriger les NULL bloquants pour le module "%s"', $moduleName),
            'modules' => $modulesWithIssues,
            'selected_module' => $moduleName,
            'changes' => [
                'module' => $moduleName,
                'keys_affected' => count($keyGroups),
                'total_entries_to_fix' => count($moduleNulls),
            ],
            'steps' => $this->buildPreviewSteps($keyGroups),
        ];
    }

    /**
     * Build preview steps from key groups for template display
     *
     * @return array<array{step: int, table: string, label: string, missing: int, status: string}>
     */
    private function buildPreviewSteps(array $keyGroups): array
    {
        $steps = [];
        $i = 1;

        foreach ($keyGroups as $keyName => $entries) {
            $scopes = [];
            foreach ($entries as $entry) {
                if ($entry['id_shop'] !== null && (int) $entry['id_shop'] > 0) {
                    $scopes[] = 'shop ' . $entry['id_shop'];
                } elseif ($entry['id_shop_group'] !== null && (int) $entry['id_shop_group'] > 0) {
                    $scopes[] = 'group ' . $entry['id_shop_group'];
                }
            }

            $steps[] = [
                'step' => $i++,
                'table' => 'configuration',
                'label' => sprintf(
                    '%s : NULL dans %s → copie valeur globale (%s)',
                    $keyName,
                    implode(', ', $scopes),
                    $entries[0]['will_set_to']
                ),
                'missing' => count($entries),
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    /**
     * Apply fix for a specific module: replace blocking NULLs with global values
     */
    private function fixModule(string $moduleName): array
    {
        try {
            $blockingNulls = $this->loadModuleBlockingNulls($moduleName);

            if (count($blockingNulls) === 0) {
                return [
                    'success' => true,
                    'type' => 'module_config',
                    'message' => sprintf('Aucun NULL bloquant trouvé pour le module "%s"', $moduleName),
                    'rows_affected' => 0,
                ];
            }

            $fixedCount = 0;

            $this->connection->beginTransaction();
            try {
                foreach ($blockingNulls as $null) {
                    $this->connection->update(
                        "{$this->prefix}configuration",
                        [
                            'value' => $null['raw_global_value'],
                            'date_upd' => date('Y-m-d H:i:s'),
                        ],
                        ['id_configuration' => $null['id_configuration']]
                    );
                    ++$fixedCount;
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw $e;
            }

            $remaining = $this->loadModuleBlockingNulls($moduleName);

            return [
                'success' => count($remaining) === 0,
                'type' => 'module_config',
                'module' => $moduleName,
                'rows_affected' => $fixedCount,
                'remaining_issues' => count($remaining),
                'message' => count($remaining) === 0
                    ? sprintf('%d entrée(s) corrigée(s) pour le module "%s"', $fixedCount, $moduleName)
                    : sprintf('%d entrée(s) corrigée(s) mais %d problème(s) restant(s)', $fixedCount, count($remaining)),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'module_config',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function previewShopOverride(array $options): array
    {
        $allOverrides = $this->loadAllShopOverrides();
        $modulesWithIssues = $this->groupByModule($allOverrides);

        if (empty($options['module'])) {
            if (count($modulesWithIssues) === 1) {
                $options['module'] = $modulesWithIssues[0]['name'];
            } else {
                return [
                    'success' => true,
                    'type' => 'shop_override',
                    'description' => 'Configuration modules multishop - valeurs shop bloquant le global',
                    'modules' => $modulesWithIssues,
                    'selected_module' => null,
                    'changes' => [
                        'modules_with_issues' => count($modulesWithIssues),
                        'total_issues' => array_sum(array_column($modulesWithIssues, 'issue_count')),
                    ],
                ];
            }
        }

        $moduleName = $options['module'];
        if (!$this->isValidModuleName($moduleName)) {
            return ['success' => false, 'error' => 'Nom de module invalide'];
        }

        $moduleOverrides = array_filter(
            $allOverrides,
            fn (array $o) => $o['module'] === $moduleName
        );

        return $this->buildShopOverridePreview($moduleName, $moduleOverrides, $modulesWithIssues);
    }

    private function applyShopOverride(array $options): array
    {
        if (empty($options['module'])) {
            return ['success' => false, 'error' => 'Aucun module sélectionné'];
        }

        $moduleName = $options['module'];
        if (!$this->isValidModuleName($moduleName)) {
            return ['success' => false, 'error' => 'Nom de module invalide'];
        }

        return $this->fixShopOverrides($moduleName);
    }

    private function loadAllShopOverrides(): array
    {
        return $this->detectShopOverrides($this->loadAllModuleConfigs());
    }

    private function loadModuleShopOverrides(string $moduleName): array
    {
        $prefix = strtoupper($moduleName) . '_%';

        $configs = $this->connection->fetchAllAssociative(
            "SELECT id_configuration, name, value, id_shop, id_shop_group
             FROM {$this->prefix}configuration
             WHERE name LIKE ?
             ORDER BY name, id_shop_group, id_shop",
            [$prefix]
        );

        foreach ($configs as &$row) {
            $row['module_name'] = $moduleName;
        }
        unset($row);

        return $this->detectShopOverrides($configs);
    }

    private function detectShopOverrides(array $configs): array
    {
        $byKey = [];
        foreach ($configs as $row) {
            $byKey[$row['name']][] = $row;
        }

        $overrides = [];

        foreach ($byKey as $entries) {
            $globalValue = null;
            foreach ($entries as $entry) {
                if ($this->isGlobalScope($entry) && $entry['value'] !== null && $entry['value'] !== '') {
                    $globalValue = $entry['value'];
                    break;
                }
            }

            if ($globalValue === null) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($this->isGlobalScope($entry)) {
                    continue;
                }

                // Shop-group level (id_shop=0, id_shop_group>0) is a legitimate configuration tier — skip
                if ((int) ($entry['id_shop'] ?? 0) === 0) {
                    continue;
                }

                // Credential/account keys are intentionally different per shop — skip them
                if ($this->isSensitiveKey($entry['name'])) {
                    continue;
                }

                // Only flag non-null, non-empty values that differ from global
                if ($entry['value'] !== null && $entry['value'] !== '' && $entry['value'] !== $globalValue) {
                    $overrides[] = [
                        'module' => $entry['module_name'],
                        'id_configuration' => (int) $entry['id_configuration'],
                        'name' => $entry['name'],
                        'id_shop' => $entry['id_shop'] !== null ? (int) $entry['id_shop'] : null,
                        'id_shop_group' => $entry['id_shop_group'] !== null ? (int) $entry['id_shop_group'] : null,
                        'current_value' => $this->maskSensitiveValue($entry['name'], $entry['value']),
                        'raw_current_value' => $entry['value'],
                        'global_value' => $this->maskSensitiveValue($entry['name'], $globalValue),
                        'raw_global_value' => $globalValue,
                    ];
                }
            }
        }

        return $overrides;
    }

    private function buildShopOverridePreview(string $moduleName, array $overrides, array $modulesWithIssues): array
    {
        $keyGroups = [];
        foreach ($overrides as $override) {
            $keyGroups[$override['name']][] = $override;
        }

        $steps = [];
        $i = 1;
        foreach ($keyGroups as $keyName => $entries) {
            $scopes = [];
            foreach ($entries as $entry) {
                if ($entry['id_shop'] !== null && (int) $entry['id_shop'] > 0) {
                    $scopes[] = 'shop ' . $entry['id_shop'];
                } elseif ($entry['id_shop_group'] !== null && (int) $entry['id_shop_group'] > 0) {
                    $scopes[] = 'group ' . $entry['id_shop_group'];
                }
            }

            $steps[] = [
                'step' => $i++,
                'table' => 'configuration',
                'label' => sprintf(
                    '%s : %s a la valeur "%s" → remplacée par la valeur globale "%s"',
                    $keyName,
                    implode(', ', $scopes),
                    $entries[0]['current_value'],
                    $entries[0]['global_value']
                ),
                'missing' => count($entries),
                'status' => 'pending',
            ];
        }

        return [
            'success' => true,
            'type' => 'shop_override',
            'description' => sprintf('Propager la valeur globale aux shops pour le module "%s"', $moduleName),
            'modules' => $modulesWithIssues,
            'selected_module' => $moduleName,
            'changes' => [
                'module' => $moduleName,
                'keys_affected' => count($keyGroups),
                'total_entries_to_fix' => count($overrides),
            ],
            'steps' => $steps,
        ];
    }

    private function fixShopOverrides(string $moduleName): array
    {
        try {
            $overrides = $this->loadModuleShopOverrides($moduleName);

            if (count($overrides) === 0) {
                return [
                    'success' => true,
                    'type' => 'shop_override',
                    'message' => sprintf('Aucune valeur shop bloquante trouvée pour le module "%s"', $moduleName),
                    'rows_affected' => 0,
                ];
            }

            $fixedCount = 0;

            $this->connection->beginTransaction();
            try {
                foreach ($overrides as $override) {
                    $this->connection->update(
                        "{$this->prefix}configuration",
                        [
                            'value' => $override['raw_global_value'],
                            'date_upd' => date('Y-m-d H:i:s'),
                        ],
                        ['id_configuration' => $override['id_configuration']]
                    );
                    ++$fixedCount;
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                throw $e;
            }

            $remaining = $this->loadModuleShopOverrides($moduleName);

            return [
                'success' => count($remaining) === 0,
                'type' => 'shop_override',
                'module' => $moduleName,
                'rows_affected' => $fixedCount,
                'remaining_issues' => count($remaining),
                'message' => count($remaining) === 0
                    ? sprintf('%d entrée(s) corrigée(s) pour le module "%s"', $fixedCount, $moduleName)
                    : sprintf('%d entrée(s) corrigée(s) mais %d problème(s) restant(s)', $fixedCount, count($remaining)),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'shop_override',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine if a config key holds credential or account-specific data.
     *
     * Keys matching these patterns are intentionally different per shop
     * (API keys, account logins, merchant IDs, tokens…) and must never
     * be treated as accidental shop overrides.
     */
    private function isSensitiveKey(string $keyName): bool
    {
        $upper = strtoupper($keyName);
        foreach (['PASSWORD', 'PASSWD', 'SECRET', '_KEY', 'TOKEN', 'LOGIN', 'ACCOUNT', '_ID', 'CERT', 'CREDENTIAL'] as $pattern) {
            if (str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask sensitive values (passwords, keys) for display
     */
    private function maskSensitiveValue(string $keyName, string $value): string
    {
        if ($this->isSensitiveKey($keyName)) {
            if (strlen($value) <= 3) {
                return str_repeat('*', strlen($value));
            }

            return substr($value, 0, 3) . str_repeat('*', max(0, strlen($value) - 3));
        }

        if (strlen($value) > 60) {
            return substr($value, 0, 57) . '...';
        }

        return $value;
    }
}
