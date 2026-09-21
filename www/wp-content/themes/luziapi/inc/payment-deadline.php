<?php

/**
 * Échéance des commandes réglées par virement bancaire ou WERO.
 */

declare(strict_types=1);

use LuziApi\Shared\Domain\BusinessCalendar;
use LuziApi\Shared\Infrastructure\Wp;

if (! defined('ABSPATH')) {
    exit;
}

const LUZIAPI_PAYMENT_DUE_BUSINESS_DAYS      = 10;
const LUZIAPI_PAYMENT_REMINDER_BUSINESS_DAYS = 5;

/**
 * Ajoute un nombre de jours ouvrés (calendrier français) à une date.
 * La règle vit dans le domaine (BusinessCalendar) ; cette fonction reste le
 * point d'appel historique du workflow.
 */
function luziapi_add_business_days(\DateTimeImmutable $start, int $days): \DateTimeImmutable
{
    return BusinessCalendar::addBusinessDays($start, $days);
}

function luziapi_is_advance_payment_order(\WC_Order $order): bool
{
    return 'bacs' === $order->get_payment_method();
}

function luziapi_payment_due_timestamp(\WC_Order $order): int
{
    return Wp::int($order->get_meta('_luziapi_payment_due_at'));
}

function luziapi_payment_due_label(\WC_Order $order): string
{
    $timestamp = luziapi_payment_due_timestamp($order);
    if ($timestamp <= 0) {
        return '';
    }

    $formatted = wp_date('d/m/Y', $timestamp, new \DateTimeZone('Europe/Paris'));

    return is_string($formatted) ? $formatted : '';
}

/**
 * Programme un rappel au cinquième jour ouvré et l'annulation à l'issue du
 * dixième. Action Scheduler est privilégié car il est fourni par WooCommerce.
 *
 * @param mixed $order
 */
function luziapi_schedule_payment_deadline(int $orderId, $order = null): void
{
    if (! $order instanceof \WC_Order) {
        $order = wc_get_order($orderId);
    }

    if (! $order instanceof \WC_Order || ! luziapi_is_advance_payment_order($order) || $order->is_paid()) {
        return;
    }

    $timezone = new \DateTimeZone('Europe/Paris');
    $start    = new \DateTimeImmutable('now', $timezone);
    $reminder = luziapi_add_business_days($start, LUZIAPI_PAYMENT_REMINDER_BUSINESS_DAYS)->setTime(9, 0);
    $due      = luziapi_add_business_days($start, LUZIAPI_PAYMENT_DUE_BUSINESS_DAYS)->setTime(23, 59, 59);

    $order->update_meta_data('_luziapi_payment_due_at', (string) $due->getTimestamp());
    $order->update_meta_data('_luziapi_payment_reminder_at', (string) $reminder->getTimestamp());
    $order->save();

    luziapi_unschedule_payment_deadline($orderId);

    if (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(
            max(time() + 60, $reminder->getTimestamp()),
            'luziapi_bacs_payment_reminder',
            [$orderId],
            'luziapi'
        );
        as_schedule_single_action(
            max(time() + 120, $due->getTimestamp()),
            'luziapi_bacs_payment_expiry',
            [$orderId],
            'luziapi'
        );

        return;
    }

    wp_schedule_single_event(max(time() + 60, $reminder->getTimestamp()), 'luziapi_bacs_payment_reminder', [$orderId]);
    wp_schedule_single_event(max(time() + 120, $due->getTimestamp()), 'luziapi_bacs_payment_expiry', [$orderId]);
}

function luziapi_unschedule_payment_deadline(int $orderId): void
{
    foreach (['luziapi_bacs_payment_reminder', 'luziapi_bacs_payment_expiry'] as $hook) {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, [$orderId], 'luziapi');
        }

        wp_clear_scheduled_hook($hook, [$orderId]);
    }
}

add_action('woocommerce_order_status_on-hold', 'luziapi_schedule_payment_deadline', 20, 2);

add_action('woocommerce_order_status_changed', static function (
    int $orderId,
    string $from,
    string $to
): void {
    if ('on-hold' !== $to) {
        luziapi_unschedule_payment_deadline($orderId);
    }
}, 20, 3);

add_action('luziapi_bacs_payment_reminder', static function (int $orderId): void {
    $order = wc_get_order($orderId);
    if (! $order instanceof \WC_Order
        || ! $order->has_status('on-hold')
        || ! luziapi_is_advance_payment_order($order)
        || $order->is_paid()) {
        return;
    }

    $order->add_order_note(
        sprintf('Échéance de rappel atteinte. Échéance finale : %s.', luziapi_payment_due_label($order)),
        0
    );
    if (function_exists('WC')) {
        WC()->mailer();
    }
    do_action('luziapi_bacs_payment_reminder_notification', $orderId, $order);
});

add_action('luziapi_bacs_payment_expiry', static function (int $orderId): void {
    $order = wc_get_order($orderId);
    if (! $order instanceof \WC_Order
        || ! $order->has_status('on-hold')
        || ! luziapi_is_advance_payment_order($order)
        || $order->is_paid()) {
        return;
    }

    $reason = sprintf(
        'Le règlement par virement bancaire ou WERO n’a pas été reçu dans le délai de %d jours ouvrés.',
        LUZIAPI_PAYMENT_DUE_BUSINESS_DAYS
    );
    $order->update_meta_data('_luziapi_cancellation_reason', $reason);
    $order->save();
    $order->update_status('cancelled', 'Annulation automatique — ' . $reason);
});
