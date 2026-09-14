<?php

/**
 * Cœur partagé du test e2e du répertoire d'abonnés (Brevo, lecture seule). Inclus par
 * le wrapper local (WP-CLI) et le wrapper prod à jeton. Ne fait que DÉFINIR la fonction.
 *
 * SÛRETÉ : le seul canal sortant de l'adaptateur est `wp_remote_get` vers `api.brevo.com`.
 * On l'intercepte par `pre_http_request` et on renvoie des contacts factices — AUCUNE
 * requête ne sort, la vraie clé et les vrais contacts ne sont jamais touchés. On exerce
 * ainsi le VRAI chemin (adaptateur → parsing → domaine), y compris la prise en compte
 * des listes de blocage, et on valide la forme de réponse attendue de Brevo. Le cache
 * transient est purgé avant/après pour ne pas laisser de données factices à la page réelle.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_subscribers_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_subscribers_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';
        $transientKey = 'luziapi_brevo_subs_list_2';

        $listResponse = [
            'count'    => 5,
            'contacts' => [
                ['email' => 'a@example.test', 'emailBlacklisted' => false, 'smsBlacklisted' => false, 'listIds' => [2], 'createdAt' => '2026-01-02T10:00:00+01:00', 'attributes' => ['SMS' => '+33600000001']],
                ['email' => 'b@example.test', 'emailBlacklisted' => false, 'smsBlacklisted' => false, 'listIds' => [2], 'createdAt' => '2026-02-02T10:00:00+01:00', 'attributes' => []],
                ['email' => 'c@example.test', 'emailBlacklisted' => true, 'smsBlacklisted' => true, 'listIds' => [2], 'createdAt' => '2026-03-02T10:00:00+01:00', 'attributes' => ['SMS' => '+33600000003']],
                ['email' => 'd@example.test', 'emailBlacklisted' => false, 'smsBlacklisted' => false, 'listIds' => [2], 'createdAt' => '2026-04-02T10:00:00+01:00', 'attributes' => ['SMS' => '+33600000004']],
                // Abonné SMS uniquement : pas d'e-mail, mais un numéro SMS consenti.
                ['emailBlacklisted' => false, 'smsBlacklisted' => false, 'listIds' => [2], 'createdAt' => '2026-05-02T10:00:00+01:00', 'attributes' => ['SMS' => '+33600000005']],
            ],
        ];
        $contactA = ['email' => 'a@example.test', 'emailBlacklisted' => false, 'smsBlacklisted' => false, 'listIds' => [2], 'attributes' => ['SMS' => '+33600000001']];
        $contactC = ['email' => 'c@example.test', 'emailBlacklisted' => true, 'smsBlacklisted' => true, 'listIds' => [2], 'attributes' => ['SMS' => '+33600000003']];

        $foreignCalls = 0;
        $intercept = static function ($pre, array $args, string $url) use (&$foreignCalls, $listResponse, $contactA, $contactC) {
            if (false !== stripos($url, 'brevo.com')) {
                if (false !== stripos($url, '/contacts/lists/')) {
                    return ['response' => ['code' => 200], 'body' => wp_json_encode($listResponse)];
                }
                if (false !== stripos($url, 'a%40example.test')) {
                    return ['response' => ['code' => 200], 'body' => wp_json_encode($contactA)];
                }
                if (false !== stripos($url, 'c%40example.test')) {
                    return ['response' => ['code' => 200], 'body' => wp_json_encode($contactC)];
                }

                // Contact inconnu : Brevo répond 404.
                return ['response' => ['code' => 404], 'body' => wp_json_encode(['code' => 'document_not_found'])];
            }

            ++$foreignCalls;

            return ['response' => ['code' => 0], 'body' => ''];
        };
        add_filter('pre_http_request', $intercept, 10, 3);
        delete_transient($transientKey);

        try {
            $directory = new \LuziApi\Newsletter\Infrastructure\Brevo\BrevoSubscriberDirectory('e2e-fake-key', 2);
            $handler = new \LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersHandler($directory);
            $view = $handler->handle(new \LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersQuery(''));

            $assert('Répertoire configuré (clé injectée)', $view->configured);
            $assert('5 abonnés au total (dont 1 SMS-seul)', 5 === $view->total, 'total=' . $view->total);
            $assert('4 abonnés e-mail listés', 4 === $view->emailCount, 'emailCount=' . $view->emailCount);
            $assert('3 abonnés SMS (blacklist SMS exclue, SMS-seul inclus)', 3 === $view->smsCount, 'smsCount=' . $view->smsCount);
            $smsOnly = array_filter($view->subscribers, static fn ($s): bool => '' === $s->email && $s->smsSubscribed);
            $assert('Abonné SMS-seul (sans e-mail) bien inclus', 1 === count($smsOnly));

            $rowsBySms = [];
            foreach ($view->subscribers as $row) {
                $rowsBySms[$row->email] = $row->smsSubscribed;
            }
            $assert('a@ : SMS abonné', true === ($rowsBySms['a@example.test'] ?? null));
            $assert('c@ : SMS non abonné (blacklist SMS)', false === ($rowsBySms['c@example.test'] ?? null));

            $statusA = $directory->statusFor('a@example.test', null);
            $assert('Statut a@ : e-mail + SMS abonné', null !== $statusA && $statusA->emailSubscribed && $statusA->smsSubscribed);

            $statusC = $directory->statusFor('c@example.test', null);
            $assert('Statut c@ : blacklists respectées (e-mail + SMS non abonné)', null !== $statusC && ! $statusC->emailSubscribed && ! $statusC->smsSubscribed);

            $statusUnknown = $directory->statusFor('inconnu@example.test', null);
            $assert('Statut inconnu : null (404 géré)', null === $statusUnknown);

            $assert('Sûreté : aucun appel réseau hors Brevo', 0 === $foreignCalls, 'hors-Brevo=' . $foreignCalls);
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            delete_transient($transientKey);
            $cleanup = 'ok (filtre retiré, cache Brevo purgé — aucune donnée factice laissée)';
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
