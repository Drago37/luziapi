<?php

/**
 * Backfill de la fidélité : rétro-crédite les pots des commandes déjà « Terminée »
 * qui ne figurent pas encore au journal. Idempotent — réutilise la clé
 * `credit:{orderId}` du moteur live (INSERT IGNORE), donc rejouable sans doublon
 * et sans conflit avec le crédit automatique. Chaque écriture est datée à la date
 * de complétion réelle de la commande.
 *
 * Ne compte que les commandes ACTUELLEMENT « completed » (une commande terminée
 * puis annulée est aujourd'hui « cancelled » → non comptée, ce qui est correct).
 * Le décompte respecte la case produit `_luziapi_pot_admissible` et les
 * remboursements ; les lignes offertes sont exclues.
 *
 * Exécution locale : make backfill-loyalty-local           (crédite)
 *                    LUZIAPI_BACKFILL_DRY=1 make …          (simulation)
 * Réutilisable hors CLI via luziapi_backfill_loyalty($dry).
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rétro-crédite les pots des commandes « Terminée ». Retourne un rapport chiffré.
 *
 * @return array{orders:int, credited:int, pots:int, already:int, no_contact:int, no_pots:int, dry:bool}
 */
function luziapi_backfill_loyalty(bool $dry): array
{
    global $wpdb;

    $schema = new LoyaltySchemaManager($wpdb);
    $schema->migrate();
    $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
    $counter = new WooCommerceEligiblePotCounter();
    $resolver = new WooCommerceOrderIdentityResolver();

    $orders = wc_get_orders([
        'status' => 'completed',
        'type'   => 'shop_order',
        'limit'  => -1,
        'return' => 'objects',
    ]);

    $report = ['orders' => 0, 'credited' => 0, 'pots' => 0, 'already' => 0, 'no_contact' => 0, 'no_pots' => 0, 'dry' => $dry];

    foreach ($orders as $order) {
        if (! $order instanceof WC_Order) {
            continue;
        }
        ++$report['orders'];
        $orderId = $order->get_id();

        if ($ledger->hasEntryForIdempotencyKey('credit:' . $orderId)) {
            ++$report['already'];
            continue;
        }
        $key = $resolver->resolve($order);
        if (null === $key) {
            ++$report['no_contact'];
            continue;
        }
        $pots = $counter->countEligiblePots($order);
        if ($pots <= 0) {
            ++$report['no_pots'];
            continue;
        }

        $report['pots'] += $pots;
        if ($dry) {
            continue;
        }

        $completedAt = $order->get_date_completed() ?: $order->get_date_created();
        $occurredAt = $completedAt instanceof WC_DateTime
            ? DateTimeImmutable::createFromInterface($completedAt)->setTimezone(wp_timezone())
            : new DateTimeImmutable('now', wp_timezone());

        $inserted = $ledger->append(new NewLoyaltyEntry(
            customerKey: $key,
            type: LoyaltyEntryType::PurchaseCredited,
            potsDelta: $pots,
            rightsDelta: 0,
            sourceOrderId: $orderId,
            usageOrderId: null,
            reversalOfId: null,
            idempotencyKey: 'credit:' . $orderId,
            reason: sprintf('Rétro-crédit — commande #%d terminée : %d pot(s)', $orderId, $pots),
            createdBy: 0,
            occurredAt: $occurredAt,
            createdAt: new DateTimeImmutable('now', wp_timezone()),
        ));
        if (null !== $inserted) {
            ++$report['credited'];
        } else {
            ++$report['already'];
        }
    }

    return $report;
}

// Entrée CLI (make backfill-loyalty-local).
if (defined('WP_CLI') && WP_CLI) {
    if (! function_exists('wc_get_orders')) {
        WP_CLI::error('WooCommerce doit être actif pour le backfill de la fidélité.');
    }
    $dryRun = '1' === (string) getenv('LUZIAPI_BACKFILL_DRY');
    $report = luziapi_backfill_loyalty($dryRun);
    WP_CLI::log(sprintf(
        '%s commandes terminées : %d ; crédités : %d (%d pots) ; déjà au journal : %d ; sans contact : %d ; sans pot admissible : %d.',
        $dryRun ? '[SIMULATION] ' : '',
        $report['orders'],
        $report['credited'],
        $report['pots'],
        $report['already'],
        $report['no_contact'],
        $report['no_pots'],
    ));
    WP_CLI::success($dryRun ? 'Simulation terminée (rien écrit).' : 'Rétro-crédit terminé.');
}
