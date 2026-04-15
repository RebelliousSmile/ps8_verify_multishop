<?php
/**
 * SC Verify Multishop - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScVerifyMultishop\Service\Check;

interface CheckInterface
{
    public function run(): CheckResult;

    public function getLabel(): string;

    public function getDomain(): string;
}
