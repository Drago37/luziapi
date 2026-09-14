<?php

/**
 * Cœur partagé du test e2e de l'édition des coordonnées client (writer réel + projection).
 * Inclus par le wrapper local (WP-CLI) et le wrapper prod à jeton.
 *
 * Scénario réel : deux commandes de la MÊME personne avec deux e-mails différents
 * apparaissent comme DEUX clients (doublon) dans le répertoire. On aligne l'e-mail sur
 * les deux commandes via `WooCommerceCustomerContactWriter` (le vrai chemin d'édition) ;
 * la re-projection doit alors les FUSIONNER en un seul client. Tout est isolé (commandes
 * de test marquées, aucun e-mail réel — statut « pending » sans notification) et nettoyé.
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_customer_contact_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_customer_contact_run(): array
    {
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';
        $orderIds = [];
        $tag = 'e2e-contact-' . uniqid();
        $emailA = $tag . '.a@e2e-contact.test';
        $emailB = $tag . '.b@e2e-contact.test';
        $merged = $tag . '@e2e-contact.test';

        $projector = new \LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector();
        $repo = new \LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceOrderRepository(wp_timezone());
        $writer = new \LuziApi\Pilotage\Infrastructure\WooCommerce\WooCommerceCustomerContactWriter();

        $countGroupsFor = static function (string $needle) use ($projector, $repo): int {
            $orders = $repo->createdBetween(new DateTimeImmutable('2000-01-01', wp_timezone()), (new DateTimeImmutable('now', wp_timezone()))->modify('+1 day'));
            $profiles = $projector->project($orders, []);
            $n = 0;
            foreach ($profiles as $p) {
                foreach ($p->emails as $e) {
                    if (str_contains($e, $needle)) {
                        ++$n;
                        break;
                    }
                }
            }

            return $n;
        };

        try {
            if (! function_exists('wc_create_order')) {
                throw new \RuntimeException('WooCommerce inactif.');
            }

            foreach ([$emailA, $emailB] as $email) {
                $order = wc_create_order();
                $order->set_billing_first_name('E2E');
                $order->set_billing_last_name('Contact');
                $order->set_billing_email($email);
                $order->set_billing_phone('0600000000');
                $order->set_billing_city('Luzillé');
                $order->set_status('pending'); // aucune notification
                $order->save();
                $orderIds[] = (int) $order->get_id();
            }

            $assert('Doublon reproduit : 2 clients distincts (2 e-mails)', 2 === $countGroupsFor('@e2e-contact.test'), 'groupes=' . $countGroupsFor('@e2e-contact.test'));

            $updated = $writer->update($orderIds, '', '', $merged, '', '');
            $assert('Writer : 2 commandes mises à jour', 2 === $updated, 'maj=' . $updated);

            foreach ($orderIds as $id) {
                $o = wc_get_order($id);
                $assert('Commande #' . $id . ' : e-mail aligné', $o instanceof WC_Order && $merged === $o->get_billing_email());
            }

            $assert('Après alignement : 1 seul client (fusion)', 1 === $countGroupsFor($tag), 'groupes=' . $countGroupsFor($tag));
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            $left = 0;
            foreach ($orderIds as $id) {
                $o = wc_get_order($id);
                if ($o instanceof WC_Order) {
                    $o->delete(true);
                }
                if (wc_get_order($id) instanceof WC_Order) {
                    ++$left;
                }
            }
            $cleanup = 0 === $left ? 'ok (commandes de test supprimées)' : ($left . ' commande(s) de test résiduelle(s) !');
            $assert('Nettoyage : aucune commande de test résiduelle', 0 === $left);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
