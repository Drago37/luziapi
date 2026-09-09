<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

final readonly class PilotageController
{
    public function __construct(
        private DashboardController $dashboard,
        private CustomersController $customers,
        private TaxDeclarationController $taxDeclaration,
        private ReceiptsController $receipts,
        private ProductsController $products,
        private InventoryController $inventory,
        private QuickSaleController $quickSale,
        private ActivityController $activity,
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

        if ('receipts' === $tab) {
            $this->receipts->render();

            return;
        }

        if ('products' === $tab) {
            $this->products->render();

            return;
        }

        if ('inventory' === $tab) {
            $this->inventory->render();

            return;
        }

        if ('quick-sale' === $tab) {
            $this->quickSale->render();

            return;
        }

        if ('activity' === $tab) {
            $this->activity->render();

            return;
        }

        $this->dashboard->render();
    }
}
