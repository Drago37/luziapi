<?php

/**
 * Cœur partagé du test e2e de l'ÉCRITURE d'abonnement Brevo (inscription/désinscription
 * depuis la fiche client). Inclus par le wrapper local (WP-CLI) et le wrapper prod à jeton.
 *
 * SÛRETÉ : le seul canal sortant est `wp_remote_post` vers api.brevo.com. On l'intercepte
 * par `pre_http_request`, on CAPTURE le corps envoyé et on renvoie une réponse factice —
 * AUCUNE requête ne part, aucun vrai contact n'est touché. On exerce le VRAI chemin
 * (handler → adaptateur → payload Brevo) et on vérifie le payload (liste, blacklists,
 * attribut SMS) pour l'inscription ET la désinscription, plus la gestion d'une erreur HTTP.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_subscription_write_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_subscription_write_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';

        $captured = [];
        $foreignCalls = 0;
        $intercept = static function ($pre, array $args, string $url) use (&$captured, &$foreignCalls) {
            if (false !== stripos($url, 'brevo.com')) {
                $body = isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null;
                $captured[] = is_array($body) ? $body : [];
                // Un e-mail contenant « fail » simule un refus Brevo.
                $code = (is_array($body) && isset($body['email']) && is_string($body['email']) && false !== stripos($body['email'], 'fail')) ? 400 : 201;

                return ['response' => ['code' => $code], 'body' => wp_json_encode(['ok' => 201 === $code])];
            }

            ++$foreignCalls;

            return ['response' => ['code' => 0], 'body' => ''];
        };
        add_filter('pre_http_request', $intercept, 10, 3);

        try {
            $writer = new \LuziApi\Newsletter\Infrastructure\Brevo\BrevoSubscriberWriter('e2e-fake-key', 2);
            $handler = new \LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionHandler($writer);

            // 1. Inscription e-mail + SMS.
            $handler->handle(new \LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionCommand('a@e2e-sub.test', '+33600000001', true, true));
            $sub = $captured[0] ?? [];
            $assert('Inscription : e-mail transmis', ($sub['email'] ?? null) === 'a@e2e-sub.test');
            $assert('Inscription : ajouté à la liste 2', ($sub['listIds'] ?? null) === [2]);
            $assert('Inscription : e-mail NON blacklisté', false === ($sub['emailBlacklisted'] ?? null));
            $assert('Inscription : SMS NON blacklisté', false === ($sub['smsBlacklisted'] ?? null));
            $assert('Inscription : attribut SMS posé', (($sub['attributes'] ?? [])['SMS'] ?? null) === '+33600000001');
            $assert('Inscription : updateEnabled (upsert)', true === ($sub['updateEnabled'] ?? null));

            // 2. Désinscription des deux canaux.
            $handler->handle(new \LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionCommand('a@e2e-sub.test', '+33600000001', false, false));
            $unsub = $captured[1] ?? [];
            $assert('Désinscription : e-mail blacklisté', true === ($unsub['emailBlacklisted'] ?? null));
            $assert('Désinscription : SMS blacklisté', true === ($unsub['smsBlacklisted'] ?? null));

            // 3. Une réponse non-2xx de Brevo lève une exception (pas d'échec silencieux).
            $threw = false;
            try {
                $handler->handle(new \LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionCommand('fail@e2e-sub.test', '', true, false));
            } catch (\RuntimeException) {
                $threw = true;
            }
            $assert('Erreur Brevo (HTTP 400) → exception', $threw);

            $assert('Sûreté : aucun appel réseau hors Brevo', 0 === $foreignCalls, 'hors-Brevo=' . $foreignCalls);
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            delete_transient('luziapi_brevo_subs_list_2');
            $cleanup = 'ok (filtre retiré, cache Brevo purgé — aucun contact réel touché)';
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
