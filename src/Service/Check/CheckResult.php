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

class CheckResult
{
    public function __construct(
        public readonly string $domain,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly array $issues = [],
        public readonly string $check_query = '',
    ) {
    }
}
