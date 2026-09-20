<?php

/**
 * Cœur partagé de l'audit de la remise de volume sur les commandes Vente.
 *
 * Parcourt les commandes issues de la Vente du pilotage (`_luziapi_quick_sale`) et
 * liste celles à **≥ 2 pots payés** dont la remise « −1 € par pot dès 2 pots » est
 * absente ou incomplète (bug corrigé), avec le montant à rattraper. Le décompte
 * réutilise l'unique source `WooCommerceOrderVolumeDiscountWriter::{paidJars,missingCents}`
 * (la même que la fiche commande et le rattrapage), pour ne jamais diverger.
 *
 * Lecture seule : aucune écriture, aucun e-mail. Utilisé par l'outil local
 * (`tools/audit-vente-volume.php`, rendu texte) et par le wrapper prod à jeton
 * (`tools/audit-vente-volume-prod.php`, rendu JSON).
 */

declare(strict_types=1);

use LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderVolumeDiscountWriter;

if (! function_exists('luziapi_audit_vente_volume_data')) {
    /**
     * @return array{
     *     year: int|null,
     *     has_drift: bool,
     *     anomaly_count: int,
     *     total_missing_cents: int,
     *     missing: list<array{order: string, id: int, paid_jars: int, missing_cents: int, status: string}>
     * }
     */
    function luziapi_audit_vente_volume_data(?int $year): array
    {
        $args = [
            'limit'      => -1,
            'return'     => 'objects',
            'type'       => 'shop_order',
            'status'     => array_keys(wc_get_order_statuses()),
            'meta_key'   => '_luziapi_quick_sale',
            'meta_value' => 'yes',
            'orderby'    => 'date',
            'order'      => 'ASC',
        ];
        if (null !== $year) {
            $args['date_created'] = sprintf('%d-01-01...%d-12-31', $year, $year);
        }
        $orders = wc_get_orders($args);

        $missing = [];
        $totalMissingCents = 0;
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }
            // Les commandes annulées/remboursées ne sont plus des encaissements
            // (même critère que le rattrapage : source unique isCorrectable()).
            if (! WooCommerceOrderVolumeDiscountWriter::isCorrectable($order)) {
                continue;
            }
            $paidJars = WooCommerceOrderVolumeDiscountWriter::paidJars($order);
            $missingCents = WooCommerceOrderVolumeDiscountWriter::missingCents($order);
            if ($paidJars < 2 || $missingCents <= 0) {
                continue;
            }
            $missing[] = [
                'order'         => $order->get_order_number(),
                'id'            => $order->get_id(),
                'paid_jars'     => $paidJars,
                'missing_cents' => $missingCents,
                'status'        => $order->get_status(),
            ];
            $totalMissingCents += $missingCents;
        }

        return [
            'year'                => $year,
            'has_drift'           => [] !== $missing,
            'anomaly_count'       => count($missing),
            'total_missing_cents' => $totalMissingCents,
            'missing'             => $missing,
        ];
    }
}
