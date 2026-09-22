<?php

/**
 * Cœur partagé du test e2e de PERFORMANCE du registre des recettes sur un
 * historique volumineux. Inclus par le wrapper local (WP-CLI) et le wrapper
 * prod à jeton.
 *
 * On remplit une COPIE `CREATE TEMPORARY TABLE … LIKE` du registre avec 20 000
 * lignes réparties sur l'année, puis on rejoue la vraie requête du registre
 * (`WHERE occurred_at BETWEEN … ORDER BY …`) sur une fenêtre sélective. On
 * vérifie : la correction à l'échelle, que le plan d'exécution utilise l'index
 * `occurred_at` (donc pas de balayage complet — la requête passe à l'échelle),
 * et une borne de temps généreuse (garde-fou contre une régression O(n²)). On
 * exerce ensuite le VRAI dépôt (`WordPressReceiptRepository::occurredBetween`,
 * mapping en `ReceiptEntry`) sur un petit jeu tagué.
 *
 * Sûreté : le volume vit sur une table temporaire ; le chemin réel n'insère que
 * 5 lignes taguées de l'année 2099 (aucune vraie donnée), supprimées ensuite.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_pilotage_perf_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_pilotage_perf_run(): array
    {
        global $wpdb;
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';
        $temp = '';
        $realTouched = false;

        $schema = new \LuziApi\Shop\Infrastructure\WordPress\PilotageSchemaManager($wpdb);

        try {
            $schema->migrate();
            $real = $schema->tableName();
            $temp = $real . '_e2e_perf_tmp';
            $wpdb->query("CREATE TEMPORARY TABLE {$temp} LIKE {$real}");

            // 20 000 lignes : 100 dans la fenêtre cible (juin 2099), 19 900 de bruit
            // répartis sur les autres mois (jamais juin) — la fenêtre est donc très
            // sélective.
            $targetRows = 100;
            $noiseRows = 19_900;
            $noiseMonths = [1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12];

            $values = [];
            for ($i = 0; $i < $noiseRows; ++$i) {
                $date = sprintf('2099-%02d-%02d 12:00:00', $noiseMonths[$i % 11], ($i % 28) + 1);
                $values[] = "('{$date}', 100, 'cash', 'collection', 'noise', 1, '{$date}')";
            }
            for ($i = 0; $i < $targetRows; ++$i) {
                $values[] = "('2099-06-15 12:00:00', 100, 'cash', 'collection', 'target', 1, '2099-06-15 12:00:00')";
            }
            shuffle($values);

            $columns = '(occurred_at, amount_cents, payment_method, entry_type, description, created_by, created_at)';
            foreach (array_chunk($values, 2_000) as $chunk) {
                $wpdb->query("INSERT INTO {$temp} {$columns} VALUES " . implode(',', $chunk));
            }
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$temp}");
            $assert('Historique volumineux inséré (20 000 lignes)', 20_000 === $total, "lignes={$total}");

            $window = "occurred_at >= '2099-06-15 00:00:00' AND occurred_at <= '2099-06-15 23:59:59'";
            $registerQuery = "SELECT * FROM {$temp} WHERE {$window} ORDER BY occurred_at DESC, sequence_number DESC";

            // Correction à l'échelle.
            $start = microtime(true);
            $rows = $wpdb->get_results($registerQuery, ARRAY_A);
            $elapsed = microtime(true) - $start;
            $sum = array_sum(array_map(static fn (array $r): int => (int) $r['amount_cents'], $rows));
            $assert('Registre à l’échelle : exactement les 100 lignes de la fenêtre', 100 === count($rows), 'lignes=' . count($rows));
            $assert('Registre à l’échelle : somme correcte (100 × 1,00 € = 100 €)', 10_000 === $sum, "cents={$sum}");

            // Plan d'exécution : l'index occurred_at est utilisé (pas de full scan).
            $plan = $wpdb->get_results("EXPLAIN SELECT * FROM {$temp} WHERE {$window}", ARRAY_A);
            $planType = strtoupper((string) ($plan[0]['type'] ?? ''));
            $planKey = (string) ($plan[0]['key'] ?? '');
            $assert('Plan d’exécution : pas de balayage complet (index occurred_at utilisé)', 'ALL' !== $planType && str_contains($planKey, 'occurred_at'), "type={$planType} key={$planKey}");

            // Garde-fou de temps (généreux) contre une régression catastrophique.
            $assert('Temps de requête raisonnable (< 1 s sur 20 000 lignes)', $elapsed < 1.0, sprintf('%.3f s', $elapsed));

            // Chemin RÉEL : on exerce le vrai dépôt (occurredBetween → mapping en
            // ReceiptEntry) sur un petit jeu tagué dans la vraie table, année 2099,
            // hors de toute donnée réelle. Nettoyé dans le finally.
            $realTable = $schema->tableName();
            $realTouched = true;
            for ($i = 0; $i < 5; ++$i) {
                $wpdb->insert($realTable, [
                    'occurred_at'    => '2099-12-31 12:00:00',
                    'amount_cents'   => 100,
                    'payment_method' => 'cash',
                    'entry_type'     => 'collection',
                    'description'    => 'e2e-perf-real',
                    'created_by'     => 1,
                    'created_at'     => '2099-12-31 12:00:00',
                ]);
            }
            $repository = new \LuziApi\Shop\Infrastructure\WordPress\WordPressReceiptRepository($wpdb, $schema, wp_timezone());
            $entries = $repository->occurredBetween(
                new \DateTimeImmutable('2099-12-31 00:00:00', wp_timezone()),
                new \DateTimeImmutable('2099-12-31 23:59:59', wp_timezone()),
            );
            $entriesSum = array_sum(array_map(static fn (\LuziApi\Shop\Domain\Receipt\ReceiptEntry $e): int => $e->amount->cents(), $entries));
            $assert(
                'Vrai dépôt WordPressReceiptRepository::occurredBetween : les 5 lignes taguées sont lues et mappées',
                5 === count($entries) && 500 === $entriesSum,
                'n=' . count($entries) . " somme={$entriesSum}",
            );

            $cleanup = 'ok (volume sur table temporaire ; 5 lignes réelles taguées année 2099 supprimées)';
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            if ('' !== $temp) {
                $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp}");
            }
            if ($realTouched) {
                // Supprime toute ligne de test de l'année 2099 dans la vraie table
                // (robuste même après un échec en cours de route).
                $wpdb->query("DELETE FROM {$schema->tableName()} WHERE occurred_at >= '2099-01-01 00:00:00' AND occurred_at <= '2099-12-31 23:59:59'");
            }
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
