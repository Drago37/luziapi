<?php

/**
 * Cœur partagé du test e2e de la FICHE CLIENT dédiée (dépôt réel + schéma + surcharge
 * d'affichage). Inclus par le wrapper local (WP-CLI) et le wrapper prod à jeton.
 *
 * Scénario réel : une commande de test crée un client dans la projection. On enregistre
 * une fiche dédiée (adresse complète) via `WordPressCustomerProfileRepository` — le VRAI
 * chemin d'écriture dans la table `luziapi_customer_profiles` (créée par la migration du
 * schéma). On relit la fiche depuis la base (aller-retour SQL réel) et on vérifie que la
 * surcharge s'applique à l'affichage du profil SANS toucher la commande. Tout est isolé
 * (commande « pending », aucune notification) et nettoyé (lignes de fiche + commande).
 */

declare(strict_types=1);

if (! function_exists('luziapi_e2e_customer_profile_run')) {
    /**
     * @return array{results: list<array{label: string, ok: bool, detail: string}>, cleanup: string, fatal: ?string}
     */
    function luziapi_e2e_customer_profile_run(): array
    {
        global $wpdb;
        $results = [];
        $assert = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
            $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $fatal = null;
        $cleanup = 'non exécuté';
        $orderId = 0;
        $identityIds = [];
        $tag = 'e2e-profile-' . uniqid();
        $email = $tag . '@e2e-profile.test';

        $schema = new \LuziApi\Shop\Infrastructure\WordPress\ShopSchemaManager($wpdb);
        $projector = new \LuziApi\Shop\Domain\Customer\CustomerHistoryProjector();
        $repoOrders = new \LuziApi\Shop\Infrastructure\WooCommerce\WooCommerceOrderRepository(wp_timezone());
        $profiles = new \LuziApi\Shop\Infrastructure\WordPress\WordPressCustomerProfileRepository($wpdb, $schema);

        $findProfile = static function () use ($projector, $repoOrders, $email): ?\LuziApi\Shop\Domain\Customer\CustomerProfile {
            $orders = $repoOrders->createdBetween(new DateTimeImmutable('2000-01-01', wp_timezone()), (new DateTimeImmutable('now', wp_timezone()))->modify('+1 day'));
            foreach ($projector->project($orders, []) as $p) {
                foreach ($p->emails as $e) {
                    if ($e === $email) {
                        return $p;
                    }
                }
            }

            return null;
        };

        try {
            if (! function_exists('wc_create_order')) {
                throw new \RuntimeException('WooCommerce inactif.');
            }

            // 0. La migration crée bien la table de fiches (idempotent).
            $schema->migrate();
            $table = $schema->customerProfilesTableName();
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $assert('Migration : table des fiches présente', $exists === $table, 'table=' . (is_string($exists) ? $exists : 'absente'));

            // 1. Une commande de test → un client dans la projection.
            $order = wc_create_order();
            $order->set_billing_first_name('E2E');
            $order->set_billing_last_name('Profile');
            $order->set_billing_email($email);
            $order->set_billing_phone('0600000000');
            $order->set_billing_city('Tours');
            $order->set_status('pending'); // aucune notification
            $order->save();
            $orderId = (int) $order->get_id();

            $profile = $findProfile();
            $assert('Client projeté depuis la commande', $profile instanceof \LuziApi\Shop\Domain\Customer\CustomerProfile);
            if (! $profile instanceof \LuziApi\Shop\Domain\Customer\CustomerProfile) {
                throw new \RuntimeException('Profil de test introuvable après création de la commande.');
            }
            $identityIds = $profile->identityIds;

            // 2. Enregistrement de la fiche dédiée (adresse complète) — vrai write SQL.
            $billing = new \LuziApi\Shop\Domain\Customer\CustomerBilling(
                'Hélène', 'Dupont', '', '3 rue des Abeilles', 'Bâtiment B', '37150', 'Luzillé', 'FR', $email, '06 31 43 70 46',
            );
            $profiles->save($identityIds, $billing, 0, new DateTimeImmutable('now', wp_timezone()));

            // 3. Relecture depuis la base (aller-retour SQL réel).
            $stored = $profiles->forCustomerIds($identityIds);
            $first = $stored[$identityIds[0]] ?? null;
            $assert('Fiche relue depuis la base', $first instanceof \LuziApi\Shop\Domain\Customer\CustomerBilling);
            $assert('Adresse persistée', $first instanceof \LuziApi\Shop\Domain\Customer\CustomerBilling && '3 rue des Abeilles' === $first->address1, 'address1=' . $first->address1);
            $assert('Code postal persisté', $first instanceof \LuziApi\Shop\Domain\Customer\CustomerBilling && '37150' === $first->postcode);

            // 4. La surcharge s'applique à l'affichage du profil.
            if ($first instanceof \LuziApi\Shop\Domain\Customer\CustomerBilling) {
                $overridden = $profile->withOverride($first);
                $assert('Affichage : nom surchargé', 'Hélène Dupont' === $overridden->name, 'name=' . $overridden->name);
                $assert('Affichage : ville surchargée (Tours → Luzillé)', 'Luzillé' === $overridden->city, 'city=' . $overridden->city);
                $assert('Identité inchangée (catégorie/fidélité préservées)', $overridden->identityIds === $profile->identityIds);
            }

            // 5. La commande d'origine n'a PAS été réécrite.
            $reloaded = wc_get_order($orderId);
            $assert('Commande d’origine intacte (ville non réécrite)', $reloaded instanceof WC_Order && 'Tours' === $reloaded->get_billing_city());
        } catch (\Throwable $exception) {
            $fatal = $exception->getMessage();
        } finally {
            $leftRows = 0;
            foreach ($identityIds as $id) {
                $wpdb->delete($schema->customerProfilesTableName(), ['customer_key' => $id], ['%s']);
                $still = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $schema->customerProfilesTableName() . ' WHERE customer_key = %s', $id));
                $leftRows += is_numeric($still) ? (int) $still : 0;
            }
            $leftOrder = 0;
            if ($orderId > 0) {
                $o = wc_get_order($orderId);
                if ($o instanceof WC_Order) {
                    $o->delete(true);
                }
                if (wc_get_order($orderId) instanceof WC_Order) {
                    $leftOrder = 1;
                }
            }
            $cleanup = (0 === $leftRows && 0 === $leftOrder)
                ? 'ok (fiche de test supprimée, commande supprimée)'
                : sprintf('résidus ! fiches=%d commande=%d', $leftRows, $leftOrder);
            $assert('Nettoyage : aucun résidu', 0 === $leftRows && 0 === $leftOrder);
        }

        return ['results' => $results, 'cleanup' => $cleanup, 'fatal' => $fatal];
    }
}
