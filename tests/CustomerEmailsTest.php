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
}
