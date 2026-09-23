<?php

/**
 * Remise à zéro de l'ISOLATION FIDÉLITÉ des tests e2e (téléphones de test fixes).
 *
 * Les e2e fidélité (`e2e-loyalty-prod`, `e2e-offered-pot-prod`) utilisent des
 * téléphones FIXES (`0600000000`, `0600000001`). Le moteur agrège la fidélité par
 * identité (e-mail OU téléphone) : `autoLink()` rattache à VIE ce téléphone à
 * l'e-mail du tout premier run, et `expand()` ramène ensuite tout le cluster à la
 * lecture. Un run interrompu AVANT son cleanup y laisse des pots résiduels qui
 * gonflent la baseline des runs suivants (« Base : 1 avantage » qui échoue).
 *
 * Cet outil purge le CLUSTER d'identité atteignable depuis ces téléphones de test :
 * les liens d'identité ET les entrées de journal résiduelles. Ces données sont à
 * 100 % de test — aucun vrai client n'utilise ces numéros — et le cluster n'est
 * atteignable que depuis eux. Garde de sûreté : clés 20-hex valides et cluster de
 * taille bornée (sinon abandon, revue manuelle).
 *
 * DRY-RUN par défaut (lecture seule) ; supprime seulement si `$apply === true`.
 *
 * Exécution locale : make reset-test-loyalty-local  (dry-run)
 *                    make reset-test-loyalty-local APPLY=1
 * Réutilisable hors CLI via luziapi_reset_test_loyalty($phones, $apply).
 */

declare(strict_types=1);

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Loyalty\Infrastructure\WordPress\LoyaltySchemaManager;
use LuziApi\Loyalty\Infrastructure\WordPress\WordPressLoyaltyIdentityLinks;
use LuziApi\Shared\Infrastructure\Wp;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Téléphones de test fixes utilisés par les e2e fidélité. Toute autre valeur doit
 * être passée explicitement (ce sont les seuls seeds sûrs par défaut).
 *
 * @var list<string>
 */
const LUZIAPI_TEST_LOYALTY_PHONES = ['0600000000', '0600000001'];

if (! function_exists('luziapi_reset_test_loyalty')) {
    /**
     * @param list<string> $phones téléphones de test dont purger le cluster d'identité
     *
     * @return array{
     *     phones: list<array{phone:string, key:?string}>,
     *     cluster_keys: list<string>,
     *     cluster_size: int,
     *     ledger_rows: int,
     *     ledger_pots_sum: int,
     *     ledger_rights_sum: int,
     *     link_rows: int,
     *     applied: bool,
     *     deleted_ledger_rows: int,
     *     deleted_link_rows: int,
     *     error?: string
     * }
     */
    function luziapi_reset_test_loyalty(array $phones, bool $apply): array
    {
        global $wpdb;

        $schema = new LoyaltySchemaManager($wpdb);
        $schema->migrate();
        $links = new WordPressLoyaltyIdentityLinks($wpdb, $schema);
        $ledgerTable = $schema->ledgerTableName();
        $linksTable = $schema->identityLinksTableName();

        // Seed : clé d'identité TÉLÉPHONE de chaque numéro de test, calculée par le
        // domaine (jamais devinée), pour matcher exactement ce que le moteur écrit.
        $seedKeys = [];
        $phoneReport = [];
        foreach ($phones as $phone) {
            $key = LoyaltyIdentity::contactKeys('', $phone)['phone'];
            $phoneReport[] = ['phone' => $phone, 'key' => $key];
            if (null !== $key) {
                $seedKeys[$key] = true;
            }
        }
        $seedKeys = array_keys($seedKeys);

        $base = [
            'phones' => $phoneReport,
            'cluster_keys' => [],
            'cluster_size' => 0,
            'ledger_rows' => 0,
            'ledger_pots_sum' => 0,
            'ledger_rights_sum' => 0,
            'link_rows' => 0,
            'applied' => false,
            'deleted_ledger_rows' => 0,
            'deleted_link_rows' => 0,
        ];

        if ([] === $seedKeys) {
            return ['error' => 'Aucun téléphone de test exploitable — rien à faire.'] + $base;
        }

        // Cluster d'identité complet atteignable depuis les téléphones de test.
        $cluster = $links->expand($seedKeys);
        sort($cluster);

        // Garde de sûreté : uniquement des clés 20-hex, et un cluster de test reste
        // petit. Un cluster anormalement grand = une vraie identité liée par erreur
        // → abandon, on ne supprime RIEN.
        foreach ($cluster as $key) {
            if (1 !== preg_match('/^[0-9a-f]{20}$/', $key)) {
                return ['error' => 'Clé de cluster invalide « ' . $key . ' » — abandon.'] + $base;
            }
        }
        $maxCluster = 50;
        if (count($cluster) > $maxCluster) {
            return [
                'error' => 'Cluster inattendu (' . count($cluster) . ' > ' . $maxCluster
                    . ' clés) — revue manuelle requise, rien supprimé.',
            ] + $base + ['cluster_keys' => $cluster, 'cluster_size' => count($cluster)];
        }

        $placeholders = implode(', ', array_fill(0, count($cluster), '%s'));
        $ledgerAgg = $wpdb->get_row(Wp::prepared(
            $wpdb,
            "SELECT COUNT(*) AS c, COALESCE(SUM(pots_delta), 0) AS p, COALESCE(SUM(rights_delta), 0) AS r"
            . " FROM {$ledgerTable} WHERE customer_key IN ({$placeholders})",
            ...$cluster,
        ), ARRAY_A);
        $linkRows = Wp::int($wpdb->get_var(Wp::prepared(
            $wpdb,
            "SELECT COUNT(*) FROM {$linksTable} WHERE identity_key IN ({$placeholders})",
            ...$cluster,
        )));

        $report = [
            'phones' => $phoneReport,
            'cluster_keys' => $cluster,
            'cluster_size' => count($cluster),
            'ledger_rows' => Wp::int($ledgerAgg['c'] ?? 0),
            'ledger_pots_sum' => Wp::int($ledgerAgg['p'] ?? 0),
            'ledger_rights_sum' => Wp::int($ledgerAgg['r'] ?? 0),
            'link_rows' => $linkRows,
            'applied' => false,
            'deleted_ledger_rows' => 0,
            'deleted_link_rows' => 0,
        ];

        if (! $apply) {
            return $report;
        }
        if (0 === $report['ledger_rows'] && 0 === $report['link_rows']) {
            $report['applied'] = true; // déjà propre, rien à supprimer

            return $report;
        }

        // Suppression atomique : journal résiduel + liens du cluster de test.
        $wpdb->query('START TRANSACTION');
        try {
            $deletedLedger = $wpdb->query(Wp::prepared(
                $wpdb,
                "DELETE FROM {$ledgerTable} WHERE customer_key IN ({$placeholders})",
                ...$cluster,
            ));
            $deletedLinks = $wpdb->query(Wp::prepared(
                $wpdb,
                "DELETE FROM {$linksTable} WHERE identity_key IN ({$placeholders})",
                ...$cluster,
            ));
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');

            return ['error' => 'Échec de la purge : ' . $exception->getMessage()] + $report;
        }

        $report['applied'] = true;
        $report['deleted_ledger_rows'] = Wp::int($deletedLedger);
        $report['deleted_link_rows'] = Wp::int($deletedLinks);

        return $report;
    }
}

// Entrée CLI locale (make reset-test-loyalty-local [APPLY=1]).
if (defined('WP_CLI') && WP_CLI) {
    $apply = '1' === (string) getenv('LUZIAPI_RESET_APPLY');
    $phonesEnv = trim((string) getenv('LUZIAPI_TEST_PHONES'));
    $phones = '' === $phonesEnv
        ? LUZIAPI_TEST_LOYALTY_PHONES
        : array_values(array_filter(array_map('trim', explode(',', $phonesEnv))));

    $data = luziapi_reset_test_loyalty($phones, $apply);
    if (isset($data['error'])) {
        WP_CLI::error($data['error']);
    }
    WP_CLI::log(sprintf(
        'Cluster de test : %d clé(s) — %d entrée(s) de journal (pots %d, droits %d), %d lien(s).',
        $data['cluster_size'],
        $data['ledger_rows'],
        $data['ledger_pots_sum'],
        $data['ledger_rights_sum'],
        $data['link_rows'],
    ));
    if ($data['applied']) {
        WP_CLI::success(sprintf(
            'Purge appliquée : %d entrée(s) de journal et %d lien(s) supprimé(s).',
            $data['deleted_ledger_rows'],
            $data['deleted_link_rows'],
        ));
    } else {
        WP_CLI::log('DRY-RUN : rien supprimé. Relance avec APPLY=1 pour purger.');
    }
}
