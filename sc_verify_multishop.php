<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use ScVerifyMultishop\Traits\HaveScriptamiTab;

class sc_verify_multishop extends Module
{
    use HaveScriptamiTab;

    public const VERSION = '1.0.0';

    public function __construct()
    {
        $this->name = 'sc_verify_multishop';
        $this->version = self::VERSION;
        $this->author = 'Scriptami';
        $this->tab = 'administration';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans('Verify Multishop', [], 'Modules.Scverifymultishop.Admin');
        $this->description = $this->trans(
            'Diagnostic and fix tools for PrestaShop multishop data integrity.',
            [],
            'Modules.Scverifymultishop.Admin'
        );
    }

    /**
     * Install module
     */
    public function install(): bool
    {
        return parent::install()
            && $this->installScriptamiTab();
    }

    /**
     * Uninstall module
     */
    public function uninstall(): bool
    {
        return $this->uninstallScriptamiTab()
            && parent::uninstall();
    }

    /**
     * Check if module is active
     */
    public static function checkModuleStatus(): bool
    {
        $module = Module::getInstanceByName('sc_verify_multishop');

        return $module instanceof Module && (bool) $module->active;
    }
}
