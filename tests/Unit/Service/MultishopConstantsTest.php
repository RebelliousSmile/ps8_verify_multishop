<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use ScVerifyMultishop\Service\MultishopConstants;

class MultishopConstantsTest extends TestCase
{
    public function testExcludedProductsIsNotEmpty(): void
    {
        $this->assertNotEmpty(MultishopConstants::EXCLUDED_PRODUCTS);
    }

    public function testExcludedProductsContainsIntegers(): void
    {
        foreach (MultishopConstants::EXCLUDED_PRODUCTS as $id) {
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id);
        }
    }

    public function testGetExcludedIdsReturnsCommaSeparatedString(): void
    {
        $result = MultishopConstants::getExcludedIds();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        // Should be parseable back to the original array
        $ids = array_map('intval', explode(',', $result));
        $this->assertSame(MultishopConstants::EXCLUDED_PRODUCTS, $ids);
    }

    public function testGetExcludedIdsIsSqlSafe(): void
    {
        $result = MultishopConstants::getExcludedIds();

        // Should only contain digits and commas
        $this->assertMatchesRegularExpression('/^\d+(,\d+)*$/', $result);
    }

    public function testHasExcludedProductsReturnsTrueWhenArrayNotEmpty(): void
    {
        $this->assertTrue(MultishopConstants::hasExcludedProducts());
    }
}
