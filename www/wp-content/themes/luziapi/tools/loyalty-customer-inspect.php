<?php

/**
 * Inspection LECTURE SEULE de la fidélité d'un client, par recherche libre
 * (nom, e-mail ou téléphone). Aucune écriture.
 *
 * Pour chaque commande correspondante : statut, pots admissibles, pots offerts,
 * et si elle est DÉJÀ au journal de fidélité. Plus le solde ACTUEL du client
 * (pots nets + avantages disponibles, via les liens d'identité) et ce que le
 * backfill AJOUTERAIT (pots des commandes « Terminée » pas encore au journal).
 *
 * Sert à comprendre un comptage suspect (« il commande beaucoup mais peu de pots ») :
 * commandes non « Terminée », déjà créditées, ou sous un autre contact.
 *
 * Exécution locale : Q="gaultier" make loyalty-customer-inspect-local
 * Réutilisable hors CLI via luziapi_loyalty_customer_inspect($search).
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyProgress;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceEligiblePotCounter;
use LuziApi\Loyalty\Infrastructure\WooCommerce\WooCommerceOrderIdentityResolver;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyIdentityLinks;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyLedger;

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('luziapi_loyalty_customer_inspect')) {
    /**
     * @return array{
     *     search: string,
     *     matched: int,
     *     net_pots: int,
     *     rights_available: int,
     *     rights_acquired: int,
     *     rights_consumed: int,
     *     pots_toward_next: int,
     *     pots_until_next: int,
     *     completed_eligible_pots: int,
     *     backfill_would_add_pots: int,
     *     orders: list<array{id:int, number:string, date:string, status:string, eligible:int, offered:int, in_ledger:bool, contact:string}>
     * }
     */
    function luziapi_loyalty_customer_inspect(string $search): array
    {
        global $wpdb;

        $schema = new LoyaltySchemaManager($wpdb);
        $schema->migrate();
        $ledger = new WordPressLoyaltyLedger($wpdb, $schema, wp_timezone());
        $links = new WordPressLoyaltyIdentityLinks($wpdb, $schema);
        $counter = new WooCommerceEligiblePotCounter();
        $resolver = new WooCommerceOrderIdentityResolver();

        $needle = trim(function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search));

        $orders = wc_get_orders([
            'type'   => 'shop_order',
            'limit'  => -1,
            'return' => 'objects',
            'status' => array_keys(wc_get_order_statuses()),
            'orderby' => 'date',
            'order'  => 'ASC',
        ]);

        $rows = [];
        $keys = [];
        $completedEligible = 0;
        $backfillWouldAdd = 0;

        foreach (is_array($orders) ? $orders : [] as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }
            $haystack = trim(sprintf(
                '%s %s %s',
                $order->get_formatted_billing_full_name(),
                (string) $order->get_billing_email(),
                (string) $order->get_billing_phone(),
            ));
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
            if ('' !== $needle && ! str_contains($haystack, $needle)) {
                continue;
            }

            $key = $resolver->resolve($order);
            if (null !== $key) {
                $keys[$key] = true;
            }
            $eligible = $counter->countEligiblePots($order);
            $inLedger = $ledger->hasEntryForOrder($order->get_id());
            $status = $order->get_status();
            if ('completed' === $status) {
                $completedEligible += $eligible;
                if (! $inLedger && null !== $key) {
                    $backfillWouldAdd += $eligible;
                }
            }
            $createdAt = $order->get_date_created();

            $rows[] = [
                'id'        => $order->get_id(),
                'number'    => $order->get_order_number(),
                'date'      => $createdAt instanceof WC_DateTime ? $createdAt->date('Y-m-d') : '',
                'status'    => $status,
                'eligible'  => $eligible,
                'offered'   => $counter->countOfferedPots($order),
                'in_ledger' => $inLedger,
                'contact'   => trim(sprintf(
                    '%s %s %s',
                    $order->get_formatted_billing_full_name(),
                    (string) $order->get_billing_email(),
                    (string) $order->get_billing_phone(),
                )),
            ];
        }

        $expanded = [] === $keys ? [] : $links->expand(array_keys($keys));
        $totals = $ledger->totalsForCustomerKeys($expanded);
        $progress = new LoyaltyProgress($totals['pots'], $totals['rightsConsumed']);

        return [
            'search'                  => $search,
            'matched'                 => count($rows),
            'net_pots'                => $progress->netPots,
            'rights_available'        => $progress->rightsAvailable,
            'rights_acquired'         => $progress->rightsAcquired,
            'rights_consumed'         => $progress->rightsConsumed,
            'pots_toward_next'        => $progress->potsTowardNextReward,
            'pots_until_next'         => $progress->potsUntilNextReward(),
            'completed_eligible_pots' => $completedEligible,
            'backfill_would_add_pots' => $backfillWouldAdd,
            'orders'                  => $rows,
        ];
    }
}

// Entrée CLI locale (Q="terme" make loyalty-customer-inspect-local).
if (defined('WP_CLI') && WP_CLI) {
    if (! function_exists('wc_get_orders')) {
        WP_CLI::error('WooCommerce doit être actif pour inspecter la fidélité client.');
    }
    $search = (string) getenv('LUZIAPI_INSPECT_QUERY');
    if ('' === trim($search)) {
        WP_CLI::error('Passe un terme de recherche : Q="nom ou e-mail ou téléphone".');
    }
    $data = luziapi_loyalty_customer_inspect($search);
    WP_CLI::log(sprintf('Recherche « %s » — %d commande(s) trouvée(s).', $data['search'], $data['matched']));
    foreach ($data['orders'] as $o) {
        WP_CLI::log(sprintf(
            '  #%s  %s  %-12s  %d pot(s) admissible(s)%s  %s  [%s]',
            $o['number'],
            $o['date'],
            $o['status'],
            $o['eligible'],
            $o['offered'] > 0 ? sprintf(' (+%d offert)', $o['offered']) : '',
            $o['in_ledger'] ? 'déjà au journal' : 'PAS au journal',
            $o['contact'],
        ));
    }
    WP_CLI::log(sprintf(
        'Solde actuel : %d pots nets → %d avantage(s) disponible(s) (acquis %d, consommés %d), %d/15 vers le prochain (reste %d).',
        $data['net_pots'],
        $data['rights_available'],
        $data['rights_acquired'],
        $data['rights_consumed'],
        $data['pots_toward_next'],
        $data['pots_until_next'],
    ));
    WP_CLI::log(sprintf(
        'Commandes « Terminée » : %d pots admissibles au total ; le backfill AJOUTERAIT %d pot(s) (commandes pas encore au journal).',
        $data['completed_eligible_pots'],
        $data['backfill_would_add_pots'],
    ));
    WP_CLI::success('Inspection terminée (lecture seule).');
}
