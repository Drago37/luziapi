<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreatedQuickSale;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Port\QuickSaleOrderWriter;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Shipping;
use WC_Product;

final class WooCommerceQuickSaleOrderWriter implements QuickSaleOrderWriter
{
    private const REQUEST_LOCK_PREFIX = '_luziapi_quick_sale_lock_';

    private const PAYMENT_TITLES = [
        'cash'          => 'Espèces',
        'cheque'        => 'Chèque',
        'bank_transfer' => 'Virement bancaire',
        'wero'          => 'Wero',
        'card'          => 'Carte bancaire',
        'paypal'        => 'PayPal',
        'other'         => 'Autre',
    ];

    public function create(CreateQuickSaleCommand $command): CreatedQuickSale
    {
        $existing = $this->findByRequestId($command->requestId);
        if ($existing instanceof WC_Order) {
            return $this->toCreatedQuickSale($existing);
        }

        $lockName = self::REQUEST_LOCK_PREFIX . md5($command->requestId);
        if (! add_option($lockName, time(), '', false)) {
            $existing = $this->findByRequestId($command->requestId);
            if ($existing instanceof WC_Order) {
                return $this->toCreatedQuickSale($existing);
            }

            throw new RuntimeException('This quick sale is already being created.');
        }

        try {
            $existing = $this->findByRequestId($command->requestId);
            if ($existing instanceof WC_Order) {
                return $this->toCreatedQuickSale($existing);
            }

            return $this->createNew($command);
        } finally {
            delete_option($lockName);
        }
    }

    private function createNew(CreateQuickSaleCommand $command): CreatedQuickSale
    {
        // Résout produits et vérifie le stock sur la quantité TOTALE par produit
        // (payé + offert + fidélité peuvent viser le même produit).
        $required = [];
        foreach ([...$command->lines, ...$command->giftLines, ...$command->rewardLines] as $line) {
            $required[$line->productId] = ($required[$line->productId] ?? 0) + $line->quantity;
        }
        $resolved = [];
        foreach ($required as $productId => $quantity) {
            $product = wc_get_product($productId);
            if (! $product instanceof WC_Product || ! $product->is_purchasable() || $quantity <= 0) {
                throw new RuntimeException('Invalid quick sale product.');
            }
            if ($product->managing_stock() && null !== $product->get_stock_quantity() && $product->get_stock_quantity() < $quantity) {
                throw new RuntimeException('Insufficient product stock.');
            }
            $resolved[$productId] = $product;
        }

        $order = wc_create_order(['created_via' => 'luziapi-quick-sale', 'status' => 'pending']);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Unable to create WooCommerce order.');
        }
        foreach ($command->lines as $line) {
            $order->add_product($resolved[$line->productId], $line->quantity);
        }
        foreach ($command->giftLines as $line) {
            $this->addOfferedItem($order, $resolved[$line->productId], $line->quantity, false);
        }
        foreach ($command->rewardLines as $line) {
            $this->addOfferedItem($order, $resolved[$line->productId], $line->quantity, true);
        }
        if (null !== $command->discount) {
            WooCommerceThankYouDiscount::applyTo($order, $command->discount);
        }

        $order->set_date_created($command->occurredAt->getTimestamp());
        if ($command->paid) {
            $order->set_date_paid($command->occurredAt->getTimestamp());
        }
        $order->set_billing_first_name('' !== $command->customerName ? $command->customerName : 'Client de passage');
        $order->set_billing_email($command->email);
        $order->set_billing_phone($command->phone);
        $order->set_billing_address_1($command->address);
        $order->set_billing_postcode($command->postcode);
        $order->set_billing_city($command->city);
        $order->set_billing_country('FR');
        $order->set_payment_method(in_array($command->paymentMethod, ['bank_transfer', 'wero'], true) ? 'bacs' : 'cod');
        $order->set_payment_method_title(self::PAYMENT_TITLES[$command->paymentMethod] ?? 'Autre');
        $order->update_meta_data('_luziapi_order_source', $command->source);
        $order->update_meta_data('_luziapi_order_emails_disabled', 'yes');
        $order->update_meta_data('_luziapi_quick_sale', 'yes');
        $order->update_meta_data('_luziapi_quick_sale_request_id', $command->requestId);

        if (in_array($command->fulfillment, ['pickup', 'delivery'], true)) {
            $shipping = new WC_Order_Item_Shipping();
            $shipping->set_method_id('delivery' === $command->fulfillment ? 'free_shipping' : 'local_pickup');
            $shipping->set_method_title('delivery' === $command->fulfillment ? 'Livraison gratuite sur rendez-vous' : 'Retrait à Luzillé sur rendez-vous');
            $shipping->set_total('0');
            $order->add_item($shipping);
        }

        $order->calculate_totals();
        $order->save();
        wc_reduce_stock_levels($order->get_id());
        $order->add_order_note('Commande créée depuis la Vente LuziApi.', 0);
        $order->update_status($command->paid ? 'completed' : 'on-hold');

        if ($command->sendEmail && '' !== $command->email) {
            $order->delete_meta_data('_luziapi_order_emails_disabled');
            $order->save_meta_data();
            $emails = WC()->mailer()->get_emails();
            $email = $emails[$command->paid ? 'WC_Email_Customer_Completed_Order' : 'WC_Email_Customer_On_Hold_Order'] ?? null;
            try {
                if ($email instanceof \WC_Email_Customer_Completed_Order || $email instanceof \WC_Email_Customer_On_Hold_Order) {
                    $email->trigger($order->get_id(), $order);
                }
            } catch (\Throwable) {
                $order->add_order_note('L’e-mail demandé depuis la Vente n’a pas pu être transmis.', 0);
            }
        }

        return new CreatedQuickSale(
            $order->get_id(),
            $order->get_order_number(),
            (int) round((float) $order->get_total() * 100),
            false,
            false,
        );
    }

    /**
     * Ajoute une ligne **offerte** (0 €) : geste commercial (`$loyalty = false`) ou
     * pot offert au titre de la fidélité (`$loyalty = true`). Le stock est décompté
     * comme pour toute ligne (via `wc_reduce_stock_levels`), la commande étant neuve.
     */
    private function addOfferedItem(WC_Order $order, WC_Product $product, int $quantity, bool $loyalty): void
    {
        OfferedOrderItem::addTo($order, $product, $quantity, $loyalty);
    }

    public function markReceiptRecorded(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            throw new RuntimeException('Quick sale order could not be updated.');
        }

        $order->update_meta_data('_luziapi_quick_sale_receipt_recorded', 'yes');
        $order->save_meta_data();
    }

    private function findByRequestId(string $requestId): ?WC_Order
    {
        $orders = wc_get_orders([
            'limit'      => 1,
            'return'     => 'objects',
            'type'       => 'shop_order',
            'status'     => array_keys(wc_get_order_statuses()),
            'meta_key'   => '_luziapi_quick_sale_request_id',
            'meta_value' => $requestId,
        ]);
        $order = $orders[0] ?? null;

        return $order instanceof WC_Order ? $order : null;
    }

    private function toCreatedQuickSale(WC_Order $order): CreatedQuickSale
    {
        return new CreatedQuickSale(
            $order->get_id(),
            $order->get_order_number(),
            (int) round((float) $order->get_total() * 100),
            'yes' === $order->get_meta('_luziapi_quick_sale_receipt_recorded'),
            true,
        );
    }
}
