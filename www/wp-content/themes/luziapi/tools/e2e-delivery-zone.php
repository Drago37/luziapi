<?php

/**
 * Cœur partagé du test e2e de la validation de zone de livraison au checkout.
 * Inclus par le wrapper local (WP-CLI) et le wrapper prod à jeton. Ne fait que
 * DÉFINIR la fonction ; rien à l'inclusion.
 *
 * La règle pure (France + 37150 + ville Bléré/Luzillé) est déjà couverte en
 * unitaire (`tests/OrderWorkflowTest`). Ce e2e couvre ce que l'unitaire ne peut
 * pas : le **câblage** du hook `woocommerce_after_checkout_validation` — qu'il
 * rejette bien une livraison gratuite hors zone, accepte dans la zone, et ne
 * bloque pas quand le mode choisi n'est pas la livraison gratuite.
 *
 * TOTALEMENT SÛR : aucune commande, aucun produit, aucun e-mail — on ne fait
 * qu'exécuter le hook de validation avec un `WP_Error` en mémoire, et l'on
 * restaure la méthode d'expédition choisie en session à la fin.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_delivery_zone_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_delivery_zone_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'rien à nettoyer (aucune donnée créée)';
        $previous = null;
        $hasSession = false;

        try {
            if (! function_exists('WC')) {
                throw new \RuntimeException('WooCommerce inactif.');
            }
            if (! WC()->session) {
                WC()->initialize_session();
            }
            if (! WC()->session && class_exists('WC_Session_Handler')) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            if (! WC()->session) {
                throw new \RuntimeException('Session WooCommerce indisponible dans ce contexte.');
            }
            $hasSession = true;
            $previous = WC()->session->get('chosen_shipping_methods', []);

            $assert(
                'Câblage : validation branchée sur woocommerce_after_checkout_validation',
                false !== has_action('woocommerce_after_checkout_validation'),
            );

            $validate = static function (array $data): array {
                $errors = new WP_Error();
                do_action('woocommerce_after_checkout_validation', $data, $errors);

                return $errors->get_error_codes();
            };
            $rejected = static fn (array $codes): bool => in_array('luziapi_delivery_city', $codes, true);

            $outsideZone = ['billing_country' => 'FR', 'billing_postcode' => '75001', 'billing_city' => 'Paris'];
            $insideZone = ['billing_country' => 'FR', 'billing_postcode' => '37150', 'billing_city' => 'Luzillé'];

            // Cas 1 & 2 : livraison gratuite choisie.
            WC()->session->set('chosen_shipping_methods', ['free_shipping:1']);
            $assert('Livraison gratuite hors zone (Paris) : REJETÉE', $rejected($validate($outsideZone)));
            $assert('Livraison gratuite à Luzillé (37150) : acceptée', ! $rejected($validate($insideZone)));

            // Cas 3 : mode retrait (pas de livraison gratuite) → aucun blocage de zone.
            WC()->session->set('chosen_shipping_methods', ['local_pickup:2']);
            $assert('Retrait hors zone : pas de blocage de zone', ! $rejected($validate($outsideZone)));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            if ($hasSession && null !== $previous) {
                WC()->session->set('chosen_shipping_methods', $previous);
            }
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
