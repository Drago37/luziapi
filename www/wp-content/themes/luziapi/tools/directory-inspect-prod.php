<?php

/**
 * Inspecteur LECTURE SEULE, sur la PRODUCTION (script à jeton, usage unique) :
 *  - listes Brevo (id, nom, nb d'abonnés) + nb de contacts dans la liste utilisée par le
 *    site (`LUZIAPI_BREVO_LIST_ID`, défaut 2) — pour diagnostiquer une liste d'abonnés
 *    incomplète ;
 *  - groupes du répertoire client (clé d'identité, nom, e-mails, téléphones, nb de
 *    commandes) — pour diagnostiquer des clients en double.
 *
 * Aucune écriture. Déposé sous `_directory-inspect.php`, appelé en HTTPS avec le jeton,
 * puis supprimé.
 */

declare(strict_types=1);

$token = 'REPLACE_WITH_TOKEN';
if (! isset($_GET['k']) || ! hash_equals($token, (string) $_GET['k'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../../wp-load.php';
header('Content-Type: application/json');

$out = ['brevo' => [], 'directory' => []];

// --- Brevo : listes + comptage de la liste configurée ----------------------
$key = get_option('sib_api_key_v3');
$listId = defined('LUZIAPI_BREVO_LIST_ID') ? (int) constant('LUZIAPI_BREVO_LIST_ID') : 2;
if (is_string($key) && '' !== trim($key)) {
    $get = static function (string $path) use ($key): ?array {
        $r = wp_remote_get('https://api.brevo.com/v3' . $path, [
            'timeout' => 10,
            'headers' => ['api-key' => $key, 'accept' => 'application/json'],
        ]);
        if ($r instanceof WP_Error) {
            return null;
        }
        $d = json_decode((string) wp_remote_retrieve_body($r), true);

        return is_array($d) ? $d : null;
    };
    $lists = $get('/contacts/lists?limit=50&offset=0');
    $out['brevo']['configured_list_id'] = $listId;
    $out['brevo']['lists'] = [];
    foreach ((is_array($lists) && isset($lists['lists']) && is_array($lists['lists'])) ? $lists['lists'] : [] as $l) {
        if (is_array($l)) {
            $out['brevo']['lists'][] = [
                'id'                => $l['id'] ?? null,
                'name'              => $l['name'] ?? null,
                'totalSubscribers'  => $l['totalSubscribers'] ?? null,
                'uniqueSubscribers' => $l['uniqueSubscribers'] ?? null,
                'totalBlacklisted'  => $l['totalBlacklisted'] ?? null,
            ];
        }
    }
    $inList = $get('/contacts/lists/' . $listId . '/contacts?limit=500&offset=0');
    $out['brevo']['configured_list_count'] = is_array($inList) ? ($inList['count'] ?? null) : null;
    $out['brevo']['configured_list_emails'] = array_map(
        static fn ($c): string => is_array($c) && isset($c['email']) ? (string) $c['email'] : '?',
        (is_array($inList) && isset($inList['contacts']) && is_array($inList['contacts'])) ? $inList['contacts'] : []
    );
} else {
    $out['brevo']['error'] = 'clé API Brevo absente (option sib_api_key_v3)';
}

// --- Répertoire client : groupes ------------------------------------------
if (class_exists(\LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository::class)) {
    $tz = wp_timezone();
    $orders = (new \LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository($tz))
        ->createdBetween(new DateTimeImmutable('2000-01-01', $tz), (new DateTimeImmutable('now', $tz))->modify('+1 day'));
    $profiles = (new \LuziApi\Shop\Domain\Customer\CustomerHistoryProjector())->project($orders, []);
    foreach ($profiles as $p) {
        $out['directory'][] = [
            'name'   => $p->name,
            'emails' => $p->emails,
            'phones' => $p->phones,
            'orders' => count($p->orders),
        ];
    }
    $out['directory_count'] = count($profiles);
    $out['orders_count'] = count($orders);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
