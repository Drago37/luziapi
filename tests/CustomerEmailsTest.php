<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CustomerEmailsTest extends TestCase
{
    public function testSuccessfulCustomerEmailAddsPrivateTraceWithoutRecipient(): void
    {
        $order        = new WC_Order();
        $email        = new WC_Email('customer_note', true, 'Une note sur la commande n°1042');
        $email->object = $order;

        luziapi_record_customer_email_delivery(true, $email->id, $email);

        self::assertSame(
            ['E-mail client « Une note sur la commande n°1042 » transmis au service de messagerie.'],
            $order->get_test_notes()
        );
        self::assertStringNotContainsString('@', implode('', $order->get_test_notes()));
    }

    public function testFailedCustomerEmailAddsExplicitTransportFailure(): void
    {
        $order        = new WC_Order();
        $email        = new WC_Email('customer_failed_order', true, 'Commande non aboutie');
        $email->object = $order;

        luziapi_record_customer_email_delivery(false, $email->id, $email);

        self::assertSame(
            ['E-mail client « Commande non aboutie » non transmis — échec du transport.'],
            $order->get_test_notes()
        );
    }

    public function testAdminEmailDoesNotAddCustomerTrace(): void
    {
        $order        = new WC_Order();
        $email        = new WC_Email('new_order', false, 'Nouvelle commande');
        $email->object = $order;

        luziapi_record_customer_email_delivery(true, $email->id, $email);

        self::assertSame([], $order->get_test_notes());
    }

    public function testDisabledOrderRecordsIntentionalSuppressionInsteadOfTransportFailure(): void
    {
        $order = new WC_Order();
        $order->update_meta_data(LUZIAPI_ORDER_EMAILS_DISABLED_META, 'yes');
        $email         = new WC_Email('customer_note', true, 'Information sur la commande');
        $email->object = $order;

        luziapi_record_customer_email_delivery(false, $email->id, $email);

        self::assertSame(
            ['E-mail client « Information sur la commande » non envoyé — désactivé pour cette commande.'],
            $order->get_test_notes()
        );
    }

    public function testMailCallbackIsDisabledForEveryEmailAttachedToDisabledOrder(): void
    {
        $order = new WC_Order();
        $order->update_meta_data(LUZIAPI_ORDER_EMAILS_DISABLED_META, 'yes');
        $email         = new WC_Email('new_order', false, 'Nouvelle commande');
        $email->object = $order;

        self::assertSame(
            '__return_false',
            luziapi_maybe_disable_order_mail_callback('wp_mail', $email)
        );
    }

    public function testMailCallbackRemainsUnchangedForNormalOrder(): void
    {
        $email         = new WC_Email('new_order', false, 'Nouvelle commande');
        $email->object = new WC_Order();

        self::assertSame('wp_mail', luziapi_maybe_disable_order_mail_callback('wp_mail', $email));
    }
}
