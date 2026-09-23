<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress\Admin;

final readonly class AdminMenu
{
    public const PAGE_SLUG = 'luziapi-pilotage';

    public function __construct(private PilotageController $pilotage)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'woocommerce',
                'Tableau de pilotage LuziApi',
                'Tableau de bord',
                'edit_shop_orders',
                self::PAGE_SLUG,
                [$this->pilotage, 'render'],
                0,
            );
        }, 20);

        add_action('admin_menu', static function (): void {
            global $submenu;

            if (! is_array($submenu) || ! isset($submenu['woocommerce']) || ! is_array($submenu['woocommerce'])) {
                return;
            }

            $woocommerceItems = $submenu['woocommerce'];
            foreach ($woocommerceItems as $index => $item) {
                if (! is_array($item) || self::PAGE_SLUG !== ($item[2] ?? null)) {
                    continue;
                }

                unset($woocommerceItems[$index]);
                array_unshift($woocommerceItems, $item);
                $submenu['woocommerce'] = array_values($woocommerceItems);

                break;
            }
        }, 999);
    }
}
