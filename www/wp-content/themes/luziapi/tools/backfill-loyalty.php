<?php

/**
 * Backfill de la fidélité : rétro-crédite les pots des commandes déjà « Terminée »
 * qui ne figurent pas encore au journal. Idempotent à deux niveaux : (1) on ignore
 * toute commande ayant DÉJÀ la moindre écriture au journal — quelle que soit la clé,
 * y compris les clés `reconcile-*` du moteur live — pour ne jamais recréditer une
 * commande déjà prise en compte ; (2) l'écriture elle-même porte la clé
 * `credit:{orderId}` en INSERT IGNORE, donc un second passage du backfill ne double
 * rien. Chaque écriture est datée à la date de complétion réelle de la commande (les
 * pots n'expirent pas : tout l'historique « Terminée » est rattrapé, sans plancher).
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
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyIdentityLinks;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rétro-crédite les pots des commandes « Terminée ». Retourne un rapport chiffré.
 *
 * @param list<int> $onlyOrderIds Restreint le backfill à ces commandes (vide = toutes
 *                                les commandes terminées). Sert aux tests isolés et à
 *                                un déploiement prudent par sous-ensemble.
 * @param bool      $collectDetail Ajoute au rapport un détail PAR CLIENT (libellé,
 *                                 pots, nombre de commandes) des pots qui seraient
 *                                 crédités — pour un contrôle avant application.
 *                                 Lecture seule, sans effet sur l'écriture.
 *
 * @return array{orders:int, credited:int, pots:int, already:int, no_contact:int, no_pots:int, dry:bool, detail?: list<array{label:string, pots:int, orders:int}>}
 */
function luziapi_backfill_loyalty(bool $dry, array $onlyOrderIds = [], bool $collectDetail = false): array
{
    global $wpdb;

    $schema = new LoyaltySchemaManager($wpdb);
    $schema->migrate();
    $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
    $links = new WordPressLoyaltyIdentityLinks($wpdb, $schema);
    $counter = new WooCommerceEligiblePotCounter();
    $resolver = new WooCommerceOrderIdentityResolver();
    $placeholderEmailKeys = \LuziApi\Loyalty\Infrastructure\WooCommerce\LoyaltyPlaceholderEmails::emailKeys();

    $orders = wc_get_orders([
        'status' => 'completed',
        'type'   => 'shop_order',
        'limit'  => -1,
        'return' => 'objects',
    ]);

    // Ciblage optionnel par liste d'IDs, filtré côté PHP pour rester portable
    // HPOS ↔ legacy (wc_get_orders n'expose pas de filtre d'IDs fiable partout).
    if ([] !== $onlyOrderIds) {
        $allowed = array_flip(array_map('intval', $onlyOrderIds));
        $orders = array_values(array_filter(
            $orders,
            static fn ($order): bool => $order instanceof WC_Order && isset($allowed[$order->get_id()])
        ));
    }

    $report = ['orders' => 0, 'credited' => 0, 'pots' => 0, 'already' => 0, 'no_contact' => 0, 'no_pots' => 0, 'dry' => $dry];
    /** @var array<string, array{label:string, pots:int, orders:int}> $detail */
    $detail = [];

    foreach ($orders as $order) {
        if (! $order instanceof WC_Order) {
            continue;
        }
        ++$report['orders'];
        $orderId = $order->get_id();

        // Semer les liens d'identité (auto-liaison PRUDENTE) pour toute commande terminée
        // NON exclue, avant même le saut ci-dessous : plusieurs téléphones d'un même
        // e-mail (changement de numéro) se relient rétroactivement, mais un téléphone déjà
        // rattaché n'absorbe pas un 2ᵉ e-mail (foyer partagé → fusion manuelle).
        if (! $dry && 'yes' !== (string) $order->get_meta('_luziapi_loyalty_excluded')) {
            $contactKeys = $resolver->contactKeys($order);
            if (null !== $contactKeys['email']
                && null !== $contactKeys['phone']
                && ! in_array($contactKeys['email'], $placeholderEmailKeys, true)) {
                $links->autoLink($contactKeys['email'], $contactKeys['phone']);
            }
        }

        // On ne rétro-crédite QUE les commandes jamais vues par le moteur. Tester la
        // seule clé `credit:{orderId}` ne suffit pas : le moteur live crédite désormais
        // par RÉCONCILIATION (clés `reconcile-pots:*`), donc une commande récente déjà
        // créditée n'a pas de clé `credit:` et serait recréditée → double comptage.
        if ($ledger->hasEntryForOrder($orderId)) {
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

        if ($collectDetail) {
            if (! isset($detail[$key])) {
                $name = trim((string) $order->get_formatted_billing_full_name());
                $email = trim((string) $order->get_billing_email());
                $phone = trim((string) $order->get_billing_phone());
                $label = ('' !== $name ? $name : 'Client')
                    . ('' !== $email ? ' — ' . $email : '')
                    . ('' !== $phone ? ' / ' . $phone : '');
                $detail[$key] = ['label' => $label, 'pots' => 0, 'orders' => 0];
            }
            $detail[$key]['pots'] += $pots;
            ++$detail[$key]['orders'];
        }

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

    if ($collectDetail) {
        // Tri décroissant par pots pour lire d'abord les plus gros crédits.
        $rows = array_values($detail);
        usort($rows, static fn (array $a, array $b): int => $b['pots'] <=> $a['pots']);
        $report['detail'] = $rows;
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
