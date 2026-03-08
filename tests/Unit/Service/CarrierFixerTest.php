<?php

declare(strict_types=1);

namespace ScVerifyMultishop\Tests\Unit\Service;

use ScVerifyMultishop\Service\CarrierFixer;

class CarrierFixerTest extends AbstractServiceTestCase
{
    private CarrierFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new CarrierFixer($this->connection, $this->prefix);
    }

    public function testGetSupportedTypes(): void
    {
        $this->assertSame(['carriers_payments'], $this->fixer->getSupportedTypes());
    }

    public function testPreviewReturnsUnsupportedForUnknownType(): void
    {
        $result = $this->fixer->preview('unknown');

        $this->assertFalse($result['success']);
    }

    public function testDeliveryReimportIsNotSupported(): void
    {
        $result = $this->fixer->preview('delivery_reimport');

        $this->assertFalse($result['success']);
    }

    // === preview: carriers_payments ===

    public function testPreviewCarriersPaymentsShowsState(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [['id_shop' => 1], ['id_shop' => 2]],
                [['id_carrier' => 1, 'name' => 'Colissimo'], ['id_carrier' => 2, 'name' => 'MR']],
                [['id_module' => 10, 'name' => 'paypal']]
            );

        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls(100, 2, 1); // currentDelivery, carrier1ShopCount, carrier2ShopCount

        $result = $this->fixer->preview('carriers_payments');

        $this->assertTrue($result['success']);
        $this->assertSame('carriers_payments', $result['type']);
        $this->assertSame(2, $result['changes']['carriers_to_associate']);
        $this->assertSame(2, $result['changes']['shops']);
    }

    // === apply: carriers_payments ===

    public function testApplyCarriersPaymentsHandlesException(): void
    {
        $this->connection->method('fetchFirstColumn')
            ->willThrowException(new \Exception('Connection lost'));

        $result = $this->fixer->apply('carriers_payments');

        $this->assertFalse($result['success']);
        $this->assertSame('Connection lost', $result['error']);
    }
}
