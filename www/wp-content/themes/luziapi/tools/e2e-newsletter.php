<?php

/**
 * Cœur partagé du test e2e de l'auto-envoi newsletter (mu-plugin
 * `luziapi-newsletter-autosend`). Inclus par le wrapper local (WP-CLI) et par le
 * wrapper prod à jeton. Ne fait que DÉFINIR la fonction ; rien à l'inclusion.
 *
 * SÛRETÉ (aucun e-mail ni SMS ne part vers un client) :
 *  - Publier ne fait que PLANIFIER un wp-cron `luziapi_nl_send_event` (+10 min) ;
 *    on vérifie la planification sans la laisser s'exécuter en vrai.
 *  - Le seul canal d'envoi réel est `wp_remote_post` vers `api.brevo.com` : on
 *    l'intercepte par `pre_http_request` et on renvoie une fausse réponse — AUCUNE
 *    requête ne sort. `pre_wp_mail => false` en ceinture.
 *  - Une clé API factice est injectée par filtre (le vrai chemin d'envoi est donc
 *    exercé, identique local/prod, sans lire ni altérer la vraie clé).
 *  - Nettoyage : l'article de test est supprimé et l'event dé-planifié ; le garde
 *    `_luziapi_nl_sent` bloque tout doublon entre-temps.
 *
 * Ce qui est vérifié : publication -> planification ; réédition -> pas de
 * re-planification ; exécution -> 2 appels Brevo interceptés + marquage « envoyé » ;
 * ré-exécution -> aucun nouvel envoi (anti-doublon) ; aucun appel réseau hors Brevo.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_newsletter_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_newsletter_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        // --- Ceintures de sûreté ------------------------------------------------
        add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);

        $brevoCalls = 0;
        $foreignCalls = 0;
        $intercept = static function ($pre, array $args, string $url) use (&$brevoCalls, &$foreignCalls) {
            if (false !== stripos($url, 'brevo.com')) {
                ++$brevoCalls;
                // /sendNow renvoie 204 ; la création de campagne renvoie 201 + id.
                if (false !== stripos($url, '/sendnow')) {
                    return ['response' => ['code' => 204], 'body' => ''];
                }

                return ['response' => ['code' => 201], 'body' => wp_json_encode(['id' => 999999])];
            }
            // Tout autre appel réseau pendant le test est bloqué ET compté (anomalie).
            ++$foreignCalls;

            return ['response' => ['code' => 0], 'body' => ''];
        };
        add_filter('pre_http_request', $intercept, 10, 3);

        // Clé API factice : exerce le vrai chemin d'envoi sans lire la vraie clé.
        $fakeKey = static fn (): string => 'e2e-fake-key';
        add_filter('pre_option_sib_api_key_v3', $fakeKey);

        $postId = 0;
        $hook = 'luziapi_nl_send_event';

        try {
            if (! function_exists('luziapi_nl_schedule')) {
                throw new \RuntimeException('mu-plugin « luziapi-newsletter-autosend » non chargé.');
            }

            // 1) Brouillon (ne doit rien planifier).
            $postId = (int) wp_insert_post([
                'post_title'   => 'E2E newsletter (test, à supprimer)',
                'post_content' => 'Contenu de test e2e.',
                'post_status'  => 'draft',
                'post_type'    => 'post',
            ], true);
            if ($postId <= 0) {
                throw new \RuntimeException('Création de l’article de test impossible.');
            }
            wp_clear_scheduled_hook($hook, [$postId]);
            $assert('Brouillon : aucun envoi planifié', false === wp_next_scheduled($hook, [$postId]));

            // 2) Première publication : doit PLANIFIER (et non envoyer).
            wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
            $assert('Publication : envoi planifié (+différé)', false !== wp_next_scheduled($hook, [$postId]));
            $assert('Publication : rien envoyé sur le coup (0 appel Brevo)', 0 === $brevoCalls);

            // 3) Réédition d'un article déjà publié : NE DOIT PAS re-planifier.
            wp_clear_scheduled_hook($hook, [$postId]);
            wp_update_post(['ID' => $postId, 'post_content' => 'Contenu de test e2e (édité).']);
            $assert('Réédition : aucune re-planification', false === wp_next_scheduled($hook, [$postId]));

            // 4) Exécution de l'envoi (case e-mail cochée) : appels Brevo INTERCEPTÉS.
            update_post_meta($postId, '_luziapi_nl_email', '1');
            $brevoCalls = 0;
            do_action($hook, $postId);
            $assert('Envoi e-mail : 2 appels Brevo interceptés (création + envoi)', 2 === $brevoCalls, 'appels=' . $brevoCalls);
            $assert('Envoi e-mail : article marqué « envoyé »', '' !== (string) get_post_meta($postId, '_luziapi_nl_sent', true));

            // 5) Anti-doublon : ré-exécuter ne doit plus rien envoyer.
            $brevoCalls = 0;
            do_action($hook, $postId);
            $assert('Anti-doublon : aucun nouvel envoi', 0 === $brevoCalls, 'appels=' . $brevoCalls);

            // 6) Sûreté : aucun appel réseau n'a visé autre chose que Brevo.
            $assert('Sûreté : aucun appel réseau hors Brevo', 0 === $foreignCalls, 'hors-Brevo=' . $foreignCalls);
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            remove_filter('pre_option_sib_api_key_v3', $fakeKey);
            if ($postId > 0) {
                wp_clear_scheduled_hook($hook, [$postId]);
                wp_delete_post($postId, true);
            }
            $left = ($postId > 0 && false !== wp_next_scheduled($hook, [$postId])) ? 1 : 0;
            $cleanup = 0 === $left ? 'ok (article supprimé, aucun event résiduel)' : 'event planifié résiduel !';
            $assert('Nettoyage : aucun event planifié résiduel', 0 === $left);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
