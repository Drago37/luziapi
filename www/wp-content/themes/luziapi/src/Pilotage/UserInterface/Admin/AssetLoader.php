<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

final readonly class AssetLoader
{
    public function __construct(
        private string $themeDirectory,
        private string $themeUri,
    ) {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', function (): void {
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
            if (AdminMenu::PAGE_SLUG !== $page) {
                return;
            }

            $cssPath = $this->themeDirectory . '/assets/css/admin-pilotage.css';
            $jsPath = $this->themeDirectory . '/assets/js/admin-pilotage.js';
            $chartPath = $this->themeDirectory . '/assets/vendor/chartjs/chart.umd.min.js';

            wp_enqueue_style(
                'luziapi-admin-pilotage',
                $this->themeUri . '/assets/css/admin-pilotage.css',
                [],
                (string) (@filemtime($cssPath) ?: '1.0.0'),
            );

            if (is_file($chartPath)) {
                wp_enqueue_script(
                    'luziapi-chartjs',
                    $this->themeUri . '/assets/vendor/chartjs/chart.umd.min.js',
                    [],
                    (string) (@filemtime($chartPath) ?: '4'),
                    true,
                );
            }

            // Selects enrichis en champs autocomplete : on réutilise selectWoo /
            // select2 déjà fournis par WooCommerce (aucune dépendance externe).
            wp_enqueue_style('select2');
            wp_enqueue_script('selectWoo');

            $scriptDeps = ['selectWoo'];
            if (is_file($chartPath)) {
                $scriptDeps[] = 'luziapi-chartjs';
            }

            wp_enqueue_script(
                'luziapi-admin-pilotage',
                $this->themeUri . '/assets/js/admin-pilotage.js',
                $scriptDeps,
                (string) (@filemtime($jsPath) ?: '1.0.0'),
                true,
            );
        });
    }
}
