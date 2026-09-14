<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

/**
 * Source unique des onglets du tableau de pilotage. Les vues incluent
 * `_nav.twig`, qui boucle sur `pilotage_tabs` : ajouter un onglet se fait ici,
 * une seule fois, au lieu de l'étaler dans chaque vue et chaque contrôleur.
 */
final class PilotageTabs
{
    /**
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    public static function links(string $active): array
    {
        $base = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG);
        $tab = static fn (string $slug): string => $base . '&tab=' . $slug;

        $items = [
            ['key' => 'dashboard', 'label' => 'Vue d’ensemble', 'url' => $base],
            ['key' => 'receipts', 'label' => 'Recettes', 'url' => $tab('receipts')],
            ['key' => 'tax-declaration', 'label' => 'Déclaration fiscale', 'url' => $tab('tax-declaration')],
            ['key' => 'orders', 'label' => 'Commandes', 'url' => admin_url('admin.php?page=wc-orders')],
            ['key' => 'customers', 'label' => 'Clients', 'url' => $tab('customers')],
            ['key' => 'subscribers', 'label' => 'Abonnés', 'url' => $tab('subscribers')],
            ['key' => 'loyalty', 'label' => 'Fidélité', 'url' => $tab('loyalty')],
            ['key' => 'products', 'label' => 'Produits', 'url' => $tab('products')],
            ['key' => 'inventory', 'label' => 'Stocks et lots', 'url' => $tab('inventory')],
            ['key' => 'quick-sale', 'label' => 'Vente', 'url' => $tab('quick-sale')],
            ['key' => 'activity', 'label' => 'Journal d’activité', 'url' => $tab('activity')],
        ];

        return array_map(
            static fn (array $item): array => [
                'key'    => $item['key'],
                'label'  => $item['label'],
                'url'    => $item['url'],
                'active' => $item['key'] === $active,
            ],
            $items,
        );
    }
}
