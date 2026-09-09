<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

final readonly class PilotageController
{
    public function __construct(
        private DashboardController $dashboard,
        private CustomersController $customers,
        private TaxDeclarationController $taxDeclaration,
    ) {
    }

    public function render(): void
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'dashboard';

        if ('customers' === $tab) {
            $this->customers->render();

            return;
        }

        if ('tax-declaration' === $tab) {
            $this->taxDeclaration->render();

            return;
        }

        $this->dashboard->render();
    }
}
