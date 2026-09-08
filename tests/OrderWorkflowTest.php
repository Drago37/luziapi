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

    public function testOrderSourceRecognizesOnlineCheckoutAndExplicitManualSource(): void
    {
        $checkoutOrder = new WC_Order('', [], 'checkout');
        $manualOrder   = new WC_Order('', [], 'admin');
        $manualOrder->update_meta_data(LUZIAPI_ORDER_SOURCE_META, 'phone');

        self::assertSame('online', luziapi_order_source($checkoutOrder));
        self::assertSame('phone', luziapi_order_source($manualOrder));
        self::assertSame('', luziapi_order_source(new WC_Order('', [], 'admin')));
    }

    public function testOrderEmailSuppressionIsStoredPerOrder(): void
    {
        $order = new WC_Order();

        self::assertFalse(luziapi_order_emails_disabled($order));

        $order->update_meta_data(LUZIAPI_ORDER_EMAILS_DISABLED_META, 'yes');

        self::assertTrue(luziapi_order_emails_disabled($order));
    }

    public function testFulfillmentModeIsLockedOnlyOnceHandoverStarts(): void
    {
        self::assertFalse(luziapi_order_fulfillment_is_locked(new WC_Order('', [], 'admin', 'processing')));
        self::assertTrue(luziapi_order_fulfillment_is_locked(new WC_Order('', [], 'admin', 'ready-for-pickup')));
        self::assertTrue(luziapi_order_fulfillment_is_locked(new WC_Order('', [], 'admin', 'out-for-delivery')));
        self::assertTrue(luziapi_order_fulfillment_is_locked(new WC_Order('', [], 'admin', 'completed')));
    }

    public function testManualOrderGetsNativeAdminAttributionWithoutOverwritingExistingData(): void
    {
        $manualOrder = new WC_Order('', [], 'admin');

        self::assertTrue(luziapi_maybe_set_admin_order_attribution($manualOrder));
        self::assertSame('admin', $manualOrder->get_meta(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META));
        self::assertFalse(luziapi_maybe_set_admin_order_attribution($manualOrder));

        $attributedOrder = new WC_Order('', [], 'admin');
        $attributedOrder->update_meta_data(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META, 'organic');

        self::assertFalse(luziapi_maybe_set_admin_order_attribution($attributedOrder));
        self::assertSame('organic', $attributedOrder->get_meta(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META));
    }

    public function testOnlineOrderWithoutMarketingDataRemainsUnattributed(): void
    {
        $order = new WC_Order('', [], 'checkout');
        $order->update_meta_data(LUZIAPI_ORDER_SOURCE_META, 'online');

        self::assertFalse(luziapi_maybe_set_admin_order_attribution($order));
        self::assertSame('', $order->get_meta(LUZIAPI_WC_ATTRIBUTION_SOURCE_TYPE_META));
    }

    public function testUnknownNativeAttributionGetsAnExplicitHonestLabel(): void
    {
        self::assertSame(
            'Attribution marketing indisponible',
            luziapi_format_unknown_order_attribution('Unknown', 'Unknown')
        );
        self::assertSame(
            'Administration web',
            luziapi_format_unknown_order_attribution('Web admin', 'Web admin')
        );
        self::assertSame(
            'Google',
            luziapi_format_unknown_order_attribution('Google', 'google')
        );
    }

    /**
     * @param mixed $cookie
     */
    #[DataProvider('cookieadminConsentProvider')]
    public function testCookieadminMarketingConsent(string $cookie, bool $expected): void
    {
        self::assertSame($expected, luziapi_cookieadmin_allows_order_attribution($cookie));
    }

    /** @return array<string, array{string, bool}> */
    public static function cookieadminConsentProvider(): array
    {
        return [
            'aucun choix'                => ['', false],
            'cookie invalide'            => ['not-json', false],
            'refus global'               => ['{"reject":"true"}', false],
            'acceptation globale'        => ['{"accept":"true"}', true],
            'marketing seul'             => ['{"marketing":"true"}', true],
            'statistiques sans marketing' => ['{"analytics":"true"}', false],
            'cookie encodé'              => [rawurlencode('{"marketing":true}'), true],
        ];
    }
}
