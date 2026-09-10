<?php

/**
 * Backfill des recettes : porte au registre l'encaissement de chaque commande
 * déjà « Terminée » qui n'y figure pas encore. Idempotent (n'enregistre que le
 * solde manquant), donc rejouable sans risque de doublon. Les commandes issues
 * de la Vente sont ignorées (elles portent déjà leur recette).
 *
 * Exécution : make backfill-receipts-local        (enregistre)
 *             LUZIAPI_BACKFILL_DRY=1 make …        (simulation, n'écrit rien)
 */

declare(strict_types=1);

use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Infrastructure\WordPress\AuditedReceiptRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\PilotageSchemaManager;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressActivityRepository;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressClock;
use LuziApi\Pilotage\Infrastructure\WordPress\WordPressReceiptRepository;

if (! defined('ABSPATH') || ! defined('WP_CLI')) {
    return;
}
if (! function_exists('wc_get_orders')) {
    WP_CLI::error('WooCommerce doit être actif pour le backfill des recettes.');
}

$dryRun = (string) getenv('LUZIAPI_BACKFILL_DRY') === '1';

global $wpdb;
$clock = new WordPressClock();
$schema = new PilotageSchemaManager($wpdb);
$activity = new ActivityRecorder(new WordPressActivityRepository($wpdb, $schema, $clock->timezone()), $clock);
$receipts = new AuditedReceiptRepository(new WordPressReceiptRepository($wpdb, $schema, $clock->timezone()), $activity);
$handler = new RecordOrderReceiptHandler($receipts, new RecordReceiptHandler($receipts, $clock));

$orders = wc_get_orders([
    'status' => 'completed',
    'type'   => 'shop_order',
    'limit'  => -1,
    'return' => 'objects',
]);

$recorded = 0;
$skippedQuickSale = 0;
$alreadyOk = 0;
$totalCents = 0;

foreach ($orders as $order) {
    if (! $order instanceof WC_Order) {
        continue;
    }
    if ('yes' === $order->get_meta('_luziapi_quick_sale')) {
        ++$skippedQuickSale;
        continue;
    }

    $expectedCents = (int) round(((float) $order->get_total() - (float) $order->get_total_refunded()) * 100);
    if ($expectedCents <= 0) {
        continue;
    }

    $paidAt = $order->get_date_paid() ?: $order->get_date_completed();
    $occurredAt = $paidAt instanceof WC_DateTime
        ? DateTimeImmutable::createFromInterface($paidAt)->setTimezone($clock->timezone())
        : $clock->now();

    if ($dryRun) {
        $already = $receipts->netTotalsByOrderIds([$order->get_id()])[$order->get_id()] ?? 0;
        $missing = $expectedCents - $already;
        if ($missing > 0) {
            ++$recorded;
            $totalCents += $missing;
            WP_CLI::log(sprintf('À enregistrer : commande n°%s → %.2f €', $order->get_order_number(), $missing / 100));
        } else {
            ++$alreadyOk;
        }

        continue;
    }

    $entry = $handler->handle(new RecordOrderReceiptCommand(
        $order->get_id(),
        $expectedCents,
        'bacs' === $order->get_payment_method() ? 'bank_transfer' : 'cash',
        $occurredAt,
        0,
        sprintf('Backfill encaissement — commande n°%s', $order->get_order_number()),
    ));

    if (null === $entry) {
        ++$alreadyOk;
    } else {
        ++$recorded;
        $totalCents += $entry->amount->cents();
    }
}

WP_CLI::success(sprintf(
    '%s : %d commande(s) %s (%.2f €), %d déjà à jour, %d Vente ignorée(s), %d commandes terminées au total.',
    $dryRun ? 'SIMULATION' : 'Backfill',
    $recorded,
    $dryRun ? 'à enregistrer' : 'enregistrée(s)',
    $totalCents / 100,
    $alreadyOk,
    $skippedQuickSale,
    count($orders),
));
