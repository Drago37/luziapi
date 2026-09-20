<?php

/**
 * Cœur partagé du test e2e de l'autocomplétion d'adresse (Base Adresse Nationale, lecture
 * seule). Inclus par le wrapper local (WP-CLI) et le wrapper prod à jeton. Ne fait que
 * DÉFINIR la fonction.
 *
 * SÛRETÉ : le seul canal sortant de l'adaptateur est `wp_remote_get` vers
 * api-adresse.data.gouv.fr. On l'intercepte par `pre_http_request` et on renvoie une
 * réponse GeoJSON factice — AUCUNE requête ne sort. On exerce ainsi le VRAI chemin
 * (adaptateur → wp_remote_get → parsing → domaine) et on vérifie aussi le câblage de
 * l'endpoint admin-ajax et la garde de longueur minimale.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_address_lookup_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_address_lookup_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        $geojson = [
            'type'     => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['label' => '3 Rue des Abeilles 37150 Luzillé', 'name' => '3 Rue des Abeilles', 'postcode' => '37150', 'city' => 'Luzillé']],
                ['type' => 'Feature', 'properties' => ['label' => '8 Boulevard du Port 80000 Amiens', 'name' => '8 Boulevard du Port', 'postcode' => '80000', 'city' => 'Amiens']],
            ],
        ];

        $controller = null;
        $foreignCalls = 0;
        $lastUrl = '';
        $intercept = static function ($pre, array $args, string $url) use (&$foreignCalls, &$lastUrl, $geojson) {
            if (false !== stripos($url, 'api-adresse.data.gouv.fr')) {
                $lastUrl = $url;

                return ['response' => ['code' => 200], 'body' => wp_json_encode($geojson)];
            }

            ++$foreignCalls;

            return ['response' => ['code' => 0], 'body' => ''];
        };
        add_filter('pre_http_request', $intercept, 10, 3);

        try {
            $lookup = new \LuziApi\Shop\Infrastructure\Http\BanAddressLookup();
            $handler = new \LuziApi\Shop\Application\Query\SearchAddress\SearchAddressHandler($lookup);

            $direct = $lookup->search('3 rue des abeilles', 8);
            $assert('Adaptateur : 2 suggestions parsées', 2 === count($direct), 'n=' . count($direct));
            $assert('Adaptateur : rue normalisée', isset($direct[0]) && '3 Rue des Abeilles' === $direct[0]->street);
            $assert('Adaptateur : code postal + ville', isset($direct[0]) && '37150' === $direct[0]->postcode && 'Luzillé' === $direct[0]->city);
            $assert('Adaptateur : recherche nationale simple (pas de biais lat/lon)', false === stripos($lastUrl, 'lat=') && false === stripos($lastUrl, 'lon='), 'url=' . $lastUrl);
            $assert('Adaptateur : pas de filtre type=housenumber (rues incluses)', false === stripos($lastUrl, 'type='));

            $viaHandler = $handler->handle(new \LuziApi\Shop\Application\Query\SearchAddress\SearchAddressQuery('  3 rue des abeilles  '));
            $assert('Handler : passe-plat après trim', 2 === count($viaHandler));

            $tooShort = $handler->handle(new \LuziApi\Shop\Application\Query\SearchAddress\SearchAddressQuery('ab'));
            $assert('Handler : rien sous 3 caractères', [] === $tooShort);

            // Câblage de l'endpoint admin-ajax (déterministe : on enregistre puis on vérifie).
            $controller = new \LuziApi\Shop\UserInterface\Admin\AddressLookupController($handler);
            $controller->register();
            $assert('Endpoint admin-ajax câblé', false !== has_action('wp_ajax_luziapi_address_search', [$controller, 'search']));

            $assert('Sûreté : aucun appel réseau hors BAN', 0 === $foreignCalls, 'hors-BAN=' . $foreignCalls);
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            if ($controller instanceof \LuziApi\Shop\UserInterface\Admin\AddressLookupController) {
                remove_action('wp_ajax_luziapi_address_search', [$controller, 'search']);
            }
            $cleanup = 'ok (filtre + action retirés ; aucun appel réseau réel, aucune donnée écrite)';
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
