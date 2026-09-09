<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WooCommerce;

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use WC_Order;
use WP_User;

final readonly class WooCommerceActivitySubscriber
{
    public function __construct(private ActivityRecorder $activity)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_new_order', [$this, 'orderCreated'], 100, 2);
        add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], 100, 4);
        add_action('woocommerce_process_shop_order_meta', [$this, 'orderEdited'], 1000, 2);
        add_action('woocommerce_order_note_added', [$this, 'orderNoteAdded'], 100, 2);
        add_action('user_register', [$this, 'customerCreated'], 100);
        add_action('profile_update', [$this, 'customerUpdated'], 100, 2);
    }

    public function orderCreated(int $orderId, WC_Order $order): void
    {
        $this->activity->record(
            ActivityCategory::Order,
            'order_created',
            'order',
            $orderId,
            sprintf('Commande n°%s créée', $order->get_order_number()),
            ['Origine technique' => $order->get_created_via() ?: 'WooCommerce'],
            $this->adminActorId(),
        );
    }

    /** @param mixed $order */
    public function orderStatusChanged(int $orderId, string $from, string $to, $order): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        $number = $order instanceof WC_Order ? $order->get_order_number() : (string) $orderId;
        $this->activity->record(
            ActivityCategory::Order,
            'status_changed',
            'order',
            $orderId,
            sprintf('Statut de la commande n°%s modifié', $number),
            ['Avant' => wc_get_order_status_name($from), 'Après' => wc_get_order_status_name($to)],
            $this->adminActorId(),
        );
    }

    /** @param mixed $order */
    public function orderEdited(int $orderId, $order): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        if (! $order instanceof WC_Order || ! current_user_can('edit_shop_orders')) {
            return;
        }
        $this->activity->record(
            ActivityCategory::Order,
            'order_edited',
            'order',
            $orderId,
            sprintf('Commande n°%s enregistrée depuis le back-office', $order->get_order_number()),
            [],
            get_current_user_id(),
        );
    }

    public function orderNoteAdded(int $commentId, WC_Order $order): void
    {
        $comment = get_comment($commentId);
        $actorId = $comment ? (int) $comment->user_id : $this->adminActorId();
        $visibility = '1' === (string) get_comment_meta($commentId, 'is_customer_note', true)
            ? 'Visible par le client'
            : 'Privée';
        $this->activity->record(
            ActivityCategory::Order,
            'note_added',
            'order',
            $order->get_id(),
            sprintf('Note ajoutée à la commande n°%s', $order->get_order_number()),
            ['Visibilité' => $visibility],
            $actorId,
        );
    }

    public function customerCreated(int $userId): void
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User || ! in_array('customer', $user->roles, true)) {
            return;
        }
        $this->activity->record(
            ActivityCategory::Customer,
            'customer_created',
            'customer',
            $userId,
            sprintf('Fiche client n°%d créée', $userId),
            [],
            $this->adminActorId(),
        );
    }

    public function customerUpdated(int $userId, WP_User $oldUser): void
    {
        $user = get_userdata($userId);
        if ((! $user instanceof WP_User || ! in_array('customer', $user->roles, true))
            && ! in_array('customer', $oldUser->roles, true)) {
            return;
        }
        $this->activity->record(
            ActivityCategory::Customer,
            'customer_updated',
            'customer',
            $userId,
            sprintf('Fiche client n°%d modifiée', $userId),
            [],
            $this->adminActorId(),
        );
    }

    private function adminActorId(): int
    {
        return current_user_can('edit_shop_orders') ? get_current_user_id() : 0;
    }
}
