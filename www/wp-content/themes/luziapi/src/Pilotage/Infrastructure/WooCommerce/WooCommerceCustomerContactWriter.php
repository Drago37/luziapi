<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Domain\Customer\CustomerContactWriter;
use WC_Order;

final class WooCommerceCustomerContactWriter implements CustomerContactWriter
{
    public function update(
        array $orderIds,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $city,
    ): int {
        $updated = 0;
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                continue;
            }

            // Seuls les champs renseignés sont écrits : un champ laissé vide conserve
            // la valeur existante de la commande.
            if ('' !== $firstName) {
                $order->set_billing_first_name($firstName);
            }
            if ('' !== $lastName) {
                $order->set_billing_last_name($lastName);
            }
            if ('' !== $email) {
                $order->set_billing_email($email);
            }
            if ('' !== $phone) {
                $order->set_billing_phone($phone);
            }
            if ('' !== $city) {
                $order->set_billing_city($city);
            }

            $order->save();
            ++$updated;
        }

        return $updated;
    }
}
