<?php

/**
 * Échéance des commandes réglées par virement bancaire ou WERO.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const LUZIAPI_PAYMENT_DUE_BUSINESS_DAYS      = 7;
const LUZIAPI_PAYMENT_REMINDER_BUSINESS_DAYS = 5;

/**
 * @return list<string>
 */
function luziapi_french_public_holidays(int $year): array
{
    $timezone = new \DateTimeZone('Europe/Paris');
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easter = new \DateTimeImmutable(
        sprintf('%04d-%02d-%02d', $year, $month, $day),
        $timezone
    );

    return [
        sprintf('%d-01-01', $year),
        sprintf('%d-05-01', $year),
        sprintf('%d-05-08', $year),
        sprintf('%d-07-14', $year),
        sprintf('%d-08-15', $year),
        sprintf('%d-11-01', $year),
        sprintf('%d-11-11', $year),
        sprintf('%d-12-25', $year),
        $easter->modify('+1 day')->format('Y-m-d'),
        $easter->modify('+39 days')->format('Y-m-d'),
        $easter->modify('+50 days')->format('Y-m-d'),
    ];
}

function luziapi_is_business_day(\DateTimeImmutable $date): bool
{
    $weekday = (int) $date->format('N');
    if ($weekday > 5) {
        return false;
    }

    return ! in_array(
        $date->format('Y-m-d'),
        luziapi_french_public_holidays((int) $date->format('Y')),
        true
    );
}

function luziapi_add_business_days(\DateTimeImmutable $start, int $days): \DateTimeImmutable
{
    $cursor = $start;
    $added  = 0;

    while ($added < max(0, $days)) {
        $cursor = $cursor->modify('+1 day');
        if (luziapi_is_business_day($cursor)) {
            ++$added;
        }
    }

    return $cursor;
}

function luziapi_is_advance_payment_order(\WC_Order $order): bool
{
    return 'bacs' === $order->get_payment_method();
}

function luziapi_payment_due_timestamp(\WC_Order $order): int
{
    return (int) $order->get_meta('_luziapi_payment_due_at');
}

function luziapi_payment_due_label(\WC_Order $order): string
{
    $timestamp = luziapi_payment_due_timestamp($order);
    if ($timestamp <= 0) {
        return '';
    }

    return wp_date('d/m/Y', $timestamp, new \DateTimeZone('Europe/Paris'));
}

/**
 * Programme un rappel au cinquième jour ouvré et l'annulation à l'issue du
 * septième. Action Scheduler est privilégié car il est fourni par WooCommerce.
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
