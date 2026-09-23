<?php

/**
 * Cœur partagé du test e2e du SCHÉMA du tableau de pilotage : migration
 * idempotente, correctif de nullabilité de `sequence_number`, contraintes
 * uniques qui rejettent les doublons (proxy de concurrence) et aller-retour
 * sauvegarde/restauration. Inclus par le wrapper local (WP-CLI) et le wrapper
 * prod à jeton.
 *
 * Sûreté : les tests d'unicité et de sauvegarde/restauration travaillent sur des
 * COPIES `CREATE TEMPORARY TABLE … LIKE` (structure + contraintes copiées,
 * auto-supprimées à la fin de session) — aucune donnée réelle n'est touchée. La
 * migration réelle est idempotente (dbDelta n'efface rien).
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_pilotage_schema_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_pilotage_schema_run(): array
    {
        global $wpdb;
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';
        $tempTables = [];

        $schema = new \LuziApi\Shop\Infrastructure\WordPress\ShopSchemaManager($wpdb);

        try {
            // 1. Migration idempotente : rejouer ne doit rien casser, les 6 tables existent.
            $schema->migrate();
            delete_option('luziapi_pilotage_receipts_schema_version');
            $schema->migrate(); // second passage forcé
            $assert('Migration idempotente : rejeu sans erreur SQL', '' === $wpdb->last_error, $wpdb->last_error);

            $tables = [
                'recettes'            => $schema->tableName(),
                'lots'                => $schema->lotsTableName(),
                'mouvements de stock' => $schema->stockMovementsTableName(),
                'journal d’activité'  => $schema->activityTableName(),
                'catégories clients'  => $schema->customerCategoriesTableName(),
                'fiches clients'      => $schema->customerProfilesTableName(),
            ];
            foreach ($tables as $label => $table) {
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
                $assert("Table « {$label} » présente après migration", $exists, $table);
            }

            // 2. Correctif de nullabilité de sequence_number (piège dbDelta documenté).
            $receipts = $schema->tableName();
            $column = $wpdb->get_row("SHOW COLUMNS FROM {$receipts} LIKE 'sequence_number'");
            $nullable = $column instanceof \stdClass && 'YES' === strtoupper((string) $column->Null);
            $assert('recettes.sequence_number est NULLable (correctif idempotent)', $nullable);

            // 3. Contraintes uniques : un doublon est rejeté (sur copie temporaire).
            $uniqueCases = [
                'recettes.sequence_number' => [
                    'table'  => $receipts,
                    'column' => 'sequence_number',
                    'value'  => 4242,
                    'base'   => ['occurred_at' => '2026-01-01 10:00:00', 'amount_cents' => 1000, 'payment_method' => 'cash', 'entry_type' => 'collection', 'description' => 'e2e', 'created_by' => 1, 'created_at' => '2026-01-01 10:00:00'],
                ],
                'lots.code' => [
                    'table'  => $schema->lotsTableName(),
                    'column' => 'code',
                    'value'  => 'E2E-LOT-UNIQUE',
                    'base'   => ['product_id' => 1, 'harvest_year' => 2026, 'harvested_at' => '2026-06-01', 'jarred_at' => '2026-06-01 10:00:00', 'apiary_origin' => 'e2e', 'variety' => 'e2e', 'quantity_jarred' => 10, 'created_by' => 1, 'created_at' => '2026-06-01 10:00:00'],
                ],
                'mouvements.reference_key' => [
                    'table'  => $schema->stockMovementsTableName(),
                    'column' => 'reference_key',
                    'value'  => 'e2e-ref-unique',
                    'base'   => ['product_id' => 1, 'occurred_at' => '2026-01-01 10:00:00', 'quantity_delta' => -1, 'movement_type' => 'sale', 'reason' => 'e2e', 'created_by' => 1, 'created_at' => '2026-01-01 10:00:00'],
                ],
            ];

            foreach ($uniqueCases as $label => $case) {
                $temp = $case['table'] . '_e2e_tmp';
                $wpdb->query("CREATE TEMPORARY TABLE {$temp} LIKE {$case['table']}");
                $tempTables[] = $temp;

                $first = $wpdb->insert($temp, array_merge($case['base'], [$case['column'] => $case['value']]));
                $suppressed = $wpdb->suppress_errors(true);
                $duplicate = $wpdb->insert($temp, array_merge($case['base'], [$case['column'] => $case['value']]));
                $lastError = $wpdb->last_error;
                $wpdb->suppress_errors($suppressed);
                $wpdb->last_error = '';

                $rejected = false === $duplicate && str_contains(strtolower($lastError), 'duplicate');
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$temp}");
                $assert("Contrainte unique {$label} : 1re insertion acceptée", 1 === $first);
                $assert("Contrainte unique {$label} : doublon rejeté (une seule ligne)", $rejected && 1 === $count, "lignes={$count}");
            }

            // 4. Aller-retour sauvegarde / restauration sur une copie temporaire.
            $backupSource = $receipts . '_e2e_backup';
            $wpdb->query("CREATE TEMPORARY TABLE {$backupSource} LIKE {$receipts}");
            $tempTables[] = $backupSource;
            $base = $uniqueCases['recettes.sequence_number']['base'];
            $wpdb->insert($backupSource, array_merge($base, ['sequence_number' => 1]));
            $wpdb->insert($backupSource, array_merge($base, ['sequence_number' => 2]));

            /** @var list<array<string, mixed>> $backup */
            $backup = $wpdb->get_results("SELECT * FROM {$backupSource}", ARRAY_A);
            $wpdb->query("TRUNCATE TABLE {$backupSource}");
            $emptyAfterTruncate = 0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$backupSource}");
            foreach ($backup as $row) {
                $wpdb->insert($backupSource, $row);
            }
            $restoredCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$backupSource}");
            $restoredSeq = $wpdb->get_col("SELECT sequence_number FROM {$backupSource} ORDER BY sequence_number");

            $assert('Sauvegarde : 2 lignes capturées', 2 === count($backup));
            $assert('Restauration : table vidée puis rechargée à l’identique', $emptyAfterTruncate && 2 === $restoredCount && ['1', '2'] === $restoredSeq, "count={$restoredCount}");

            $cleanup = 'ok (tables temporaires uniquement, aucune donnée réelle touchée)';
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            foreach ($tempTables as $temp) {
                $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$temp}");
            }
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
