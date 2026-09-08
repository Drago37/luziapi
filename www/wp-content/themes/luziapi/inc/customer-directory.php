<?php

/**
 * Répertoire commercial construit à partir des commandes WooCommerce.
 *
 * Il complète le rapport analytique natif, qui identifie surtout les invités
 * par e-mail, afin de retrouver aussi les clients connus uniquement par leur
 * numéro de téléphone. Aucun compte WordPress n'est créé.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

function luziapi_normalize_customer_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0033')) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '330')) {
        $digits = '33' . substr($digits, 3);
    }
    if (10 === strlen($digits) && str_starts_with($digits, '0')) {
        $digits = '33' . substr($digits, 1);
    }

    return strlen($digits) >= 6 ? $digits : '';
}

/**
 * Regroupe d'abord par e-mail. Un enregistrement sans e-mail rejoint ensuite
 * le groupe portant le même téléphone uniquement si ce téléphone ne
 * correspond qu'à une seule adresse connue. Cela évite de fusionner les
 * membres d'un foyer qui partageraient un numéro.
 *
 * @param list<array<string, mixed>> $records
 *
 * @return list<array<string, mixed>>
 */
function luziapi_group_customer_records(array $records): array
{
    $prepared = [];
    $phoneEmails = [];

    foreach ($records as $record) {
        $email = strtolower(trim((string) ($record['email'] ?? '')));
        $phone = luziapi_normalize_customer_phone((string) ($record['phone'] ?? ''));
        if ('' === $email && '' === $phone) {
            continue;
        }

        $record['email']            = $email;
        $record['normalized_phone'] = $phone;
        $prepared[]                 = $record;

        if ('' !== $email && '' !== $phone) {
            $phoneEmails[$phone][$email] = true;
        }
    }

    $grouped = [];
    foreach ($prepared as $record) {
        $email = (string) $record['email'];
        $phone = (string) $record['normalized_phone'];

        if ('' !== $email) {
            $key = 'email:' . $email;
        } elseif (1 === count($phoneEmails[$phone] ?? [])) {
            $key = 'email:' . (string) array_key_first($phoneEmails[$phone]);
        } else {
            $key = 'phone:' . $phone;
        }

        $grouped[$key][] = $record;
    }

    $customers = [];
    foreach ($grouped as $key => $customerRecords) {
        usort(
            $customerRecords,
            static fn (array $left, array $right): int => (int) ($right['timestamp'] ?? 0) <=> (int) ($left['timestamp'] ?? 0)
        );

        $latest = $customerRecords[0];
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (array $record): string => (string) ($record['email'] ?? ''),
            $customerRecords
        ))));
        $phones = [];
        $sources = [];
        $total = 0.0;

        foreach ($customerRecords as $record) {
            $phone = trim((string) ($record['phone'] ?? ''));
            $normalizedPhone = luziapi_normalize_customer_phone($phone);
            if ('' !== $normalizedPhone) {
                $phones[$normalizedPhone] = $phone;
            }
            $source = trim((string) ($record['source'] ?? ''));
            if ('' !== $source) {
                $sources[$source] = $source;
            }
            $total += (float) ($record['total'] ?? 0);
        }

        $customers[] = [
            'key'          => $key,
            'first_name'   => (string) ($latest['first_name'] ?? ''),
            'last_name'    => (string) ($latest['last_name'] ?? ''),
            'city'         => (string) ($latest['city'] ?? ''),
            'emails'       => $emails,
            'phones'       => array_values($phones),
            'sources'      => array_values($sources),
            'orders'       => $customerRecords,
            'orders_count' => count($customerRecords),
            'total'        => $total,
            'timestamp'    => (int) ($latest['timestamp'] ?? 0),
        ];
    }

    usort(
        $customers,
        static fn (array $left, array $right): int => (int) $right['timestamp'] <=> (int) $left['timestamp']
    );

    return $customers;
}

/**
 * @return list<array<string, mixed>>
 */
function luziapi_customer_directory_records(): array
{
    $orders = wc_get_orders([
        'limit'   => -1,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
        'status'  => array_keys(wc_get_order_statuses()),
        'type'    => 'shop_order',
    ]);
    $sourceLabels = luziapi_order_source_options();
    $records = [];

    foreach ($orders as $order) {
        if (! $order instanceof \WC_Order) {
            continue;
        }

        $email = trim($order->get_billing_email());
        $phone = trim($order->get_billing_phone());
        if ('' === $email && '' === luziapi_normalize_customer_phone($phone)) {
            continue;
        }

        $date = $order->get_date_created();
        $source = luziapi_order_source($order);
        $records[] = [
            'order_id'    => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'first_name'  => $order->get_billing_first_name(),
            'last_name'   => $order->get_billing_last_name(),
            'email'       => $email,
            'phone'       => $phone,
            'city'        => $order->get_billing_city(),
            'source'      => $sourceLabels[$source] ?? 'Non renseignée',
            'total'       => (float) $order->get_total(),
            'timestamp'   => $date ? $date->getTimestamp() : 0,
        ];
    }

    return $records;
}

/**
 * @param array<string, mixed> $customer
 */
function luziapi_customer_directory_matches(array $customer, string $search): bool
{
    if ('' === $search) {
        return true;
    }

    $haystack = implode(' ', [
        (string) ($customer['first_name'] ?? ''),
        (string) ($customer['last_name'] ?? ''),
        (string) ($customer['city'] ?? ''),
        implode(' ', (array) ($customer['emails'] ?? [])),
        implode(' ', (array) ($customer['phones'] ?? [])),
        implode(' ', (array) ($customer['sources'] ?? [])),
    ]);
    $haystack = strtolower(remove_accents($haystack));
    $needle = strtolower(remove_accents($search));

    return str_contains($haystack, $needle)
        || ('' !== luziapi_normalize_customer_phone($search)
            && str_contains(
                luziapi_normalize_customer_phone(implode(' ', (array) ($customer['phones'] ?? []))),
                luziapi_normalize_customer_phone($search)
            ));
}

add_action('admin_menu', static function (): void {
    add_submenu_page(
        'woocommerce',
        'Répertoire clients LuziApi',
        'Répertoire clients',
        'edit_shop_orders',
        'luziapi-customer-directory',
        'luziapi_render_customer_directory',
        57
    );
});

function luziapi_render_customer_directory(): void
{
    if (! current_user_can('edit_shop_orders')) {
        wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $customers = array_values(array_filter(
        luziapi_group_customer_records(luziapi_customer_directory_records()),
        static fn (array $customer): bool => luziapi_customer_directory_matches($customer, $search)
    ));
    $perPage = 50;
    $currentPage = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
    $totalPages = max(1, (int) ceil(count($customers) / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $pageCustomers = array_slice($customers, ($currentPage - 1) * $perPage, $perPage);
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Répertoire clients</h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=new')); ?>" class="page-title-action">Ajouter une commande</a>
        <hr class="wp-header-end">
        <p>
            Répertoire construit directement depuis les commandes : regroupement par e-mail ou,
            lorsqu’il manque, par téléphone. Il ne crée aucun compte et n’inscrit personne aux communications marketing.
        </p>
        <form method="get">
            <input type="hidden" name="page" value="luziapi-customer-directory">
            <p class="search-box">
                <label class="screen-reader-text" for="luziapi-customer-search">Rechercher un client</label>
                <input type="search" id="luziapi-customer-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Nom, e-mail ou téléphone">
                <button type="submit" class="button">Rechercher</button>
            </p>
        </form>
        <div class="tablenav top">
            <div class="alignleft actions"><?php echo esc_html(sprintf('%d client%s', count($customers), count($customers) > 1 ? 's' : '')); ?></div>
        </div>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th scope="col">Client</th>
                    <th scope="col">Coordonnées</th>
                    <th scope="col">Dernière commande</th>
                    <th scope="col">Commandes</th>
                    <th scope="col">Total</th>
                    <th scope="col">Source</th>
                </tr>
            </thead>
            <tbody>
            <?php if ([] === $pageCustomers) : ?>
                <tr><td colspan="6">Aucun client trouvé.</td></tr>
            <?php else : ?>
                <?php foreach ($pageCustomers as $customer) : ?>
                    <?php
                    $name = trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']);
                    $latestOrder = $customer['orders'][0];
                    $latestOrderUrl = admin_url('admin.php?page=wc-orders&action=edit&id=' . (int) $latestOrder['order_id']);
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($latestOrderUrl); ?>"><?php echo esc_html('' !== $name ? $name : 'Client sans nom'); ?></a></strong>
                            <?php if ('' !== (string) $customer['city']) : ?><br><?php echo esc_html((string) $customer['city']); ?><?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ((array) $customer['emails'] as $email) : ?>
                                <a href="mailto:<?php echo esc_attr((string) $email); ?>"><?php echo esc_html((string) $email); ?></a><br>
                            <?php endforeach; ?>
                            <?php foreach ((array) $customer['phones'] as $phone) : ?>
                                <a href="tel:+<?php echo esc_attr(luziapi_normalize_customer_phone((string) $phone)); ?>"><?php echo esc_html((string) $phone); ?></a><br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($latestOrderUrl); ?>">Commande n°<?php echo esc_html((string) $latestOrder['order_number']); ?></a><br>
                            <?php echo esc_html(wp_date('d/m/Y à H:i', (int) $customer['timestamp'])); ?>
                        </td>
                        <td><?php echo esc_html((string) $customer['orders_count']); ?></td>
                        <td><?php echo wp_kses_post(wc_price((float) $customer['total'])); ?></td>
                        <td><?php echo esc_html(implode(', ', (array) $customer['sources'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'      => add_query_arg(['page' => 'luziapi-customer-directory', 's' => $search, 'paged' => '%#%'], admin_url('admin.php')),
                        'format'    => '',
                        'current'   => $currentPage,
                        'total'     => $totalPages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ]));
            ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
