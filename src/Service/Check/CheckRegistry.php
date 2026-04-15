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

class CheckRegistry
{
    /** @param CheckInterface[] $checks */
    public function __construct(
        private readonly array $checks = [],
    ) {
    }

    /** @return CheckResult[] */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $check->run();
        }

        return $results;
    }
}
