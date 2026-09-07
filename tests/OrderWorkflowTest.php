<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderWorkflowTest extends TestCase
{
    /**
     * @param array<string, string> $destination
     */
    #[DataProvider('destinationProvider')]
    public function testLocalDeliveryRestriction(array $destination, bool $expected): void
    {
        self::assertSame($expected, luziapi_is_local_delivery_destination($destination));
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function destinationProvider(): array
    {
        return [
            'Bléré' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Bléré'],
                true,
            ],
            'Blere sans accent' => [
                ['country' => 'fr', 'postcode' => '37150', 'city' => 'BLERE'],
                true,
            ],
            'Luzillé' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Luzillé'],
                true,
            ],
            'Luzille avec espaces' => [
                ['country' => 'FR', 'postcode' => '37 150', 'city' => '  luzille  '],
                true,
            ],
            'Autre commune du 37150' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Dierre'],
                false,
            ],
            'Bléré avec mauvais code postal' => [
                ['country' => 'FR', 'postcode' => '37000', 'city' => 'Bléré'],
                false,
            ],
            'Bléré hors de France' => [
                ['country' => 'BE', 'postcode' => '37150', 'city' => 'Bléré'],
                false,
            ],
            'Adresse incomplète' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => ''],
                false,
            ],
        ];
    }

    public function testSevenBusinessDaysSkipWeekend(): void
    {
        $start = new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-09-15', luziapi_add_business_days($start, 7)->format('Y-m-d'));
    }

    public function testBusinessDaysSkipMayDay(): void
    {
        $start = new DateTimeImmutable('2026-04-30 12:00:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-05-05', luziapi_add_business_days($start, 2)->format('Y-m-d'));
    }

    public function testBusinessDaysSkipAscension(): void
    {
        $start = new DateTimeImmutable('2026-05-13 12:00:00', new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-05-15', luziapi_add_business_days($start, 1)->format('Y-m-d'));
    }

    public function testPaymentDeadlineConstants(): void
    {
        self::assertSame(10, LUZIAPI_PAYMENT_DUE_BUSINESS_DAYS);
        self::assertSame(5, LUZIAPI_PAYMENT_REMINDER_BUSINESS_DAYS);
    }

    public function testAdvancePaymentOrderDetectsBankTransfer(): void
    {
        self::assertTrue(luziapi_is_advance_payment_order(new WC_Order('bacs')));
        self::assertFalse(luziapi_is_advance_payment_order(new WC_Order('cod')));
        self::assertFalse(luziapi_is_advance_payment_order(new WC_Order('')));
    }

    public function testFulfillmentModeFromShippingMethod(): void
    {
        self::assertSame(
            'delivery',
            luziapi_order_fulfillment_mode(new WC_Order('bacs', [new Luziapi_Test_Shipping_Method('free_shipping')]))
        );
        self::assertSame(
            'pickup',
            luziapi_order_fulfillment_mode(new WC_Order('cod', [new Luziapi_Test_Shipping_Method('local_pickup')]))
        );
        self::assertSame('unknown', luziapi_order_fulfillment_mode(new WC_Order('cod', [])));
        self::assertSame(
            'unknown',
            luziapi_order_fulfillment_mode(new WC_Order('cod', [new Luziapi_Test_Shipping_Method('flat_rate')]))
        );
    }

    public function testStatusMatchesFulfillmentBlocksMismatch(): void
    {
        $delivery = new WC_Order('bacs', [new Luziapi_Test_Shipping_Method('free_shipping')]);
        $pickup   = new WC_Order('bacs', [new Luziapi_Test_Shipping_Method('local_pickup')]);
        $unknown  = new WC_Order('bacs', []);

        // Une livraison ne peut pas passer « Prête au retrait », et inversement.
        self::assertTrue(luziapi_order_status_matches_fulfillment($delivery, 'out_for_delivery'));
        self::assertFalse(luziapi_order_status_matches_fulfillment($delivery, 'ready_for_pickup'));
        self::assertFalse(luziapi_order_status_matches_fulfillment($pickup, 'out_for_delivery'));
        self::assertTrue(luziapi_order_status_matches_fulfillment($pickup, 'ready_for_pickup'));

        // Mode indéterminé : on ne bloque rien.
        self::assertTrue(luziapi_order_status_matches_fulfillment($unknown, 'out_for_delivery'));
        self::assertTrue(luziapi_order_status_matches_fulfillment($unknown, 'ready_for_pickup'));

        // Tout autre statut passe quel que soit le mode.
        self::assertTrue(luziapi_order_status_matches_fulfillment($delivery, 'completed'));
    }
}
