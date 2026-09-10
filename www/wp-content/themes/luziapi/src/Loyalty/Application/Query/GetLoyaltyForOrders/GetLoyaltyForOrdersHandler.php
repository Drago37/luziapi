<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Query\GetLoyaltyForOrders;

use LuziApi\Loyalty\Application\Port\OrderContactKeys;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\CustomerLoyaltyView;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;

/**
 * État de fidélité d'un client à partir des commandes auxquelles sa session de
 * suivi donne accès (aucun compte). Résout les clés d'identité des commandes puis
 * agrège le journal comme la fiche client.
 */
final readonly class GetLoyaltyForOrdersHandler
{
    public function __construct(
        private OrderContactKeys $contactKeys,
        private GetCustomerLoyaltyHandler $loyalty,
    ) {
    }

    /**
     * @param list<int> $orderIds
     */
    public function handle(array $orderIds): CustomerLoyaltyView
    {
        $keys = $this->contactKeys->forOrderIds($orderIds);
        if ([] === $keys) {
            return CustomerLoyaltyView::empty();
        }

        return $this->loyalty->handle(new GetCustomerLoyaltyQuery($keys));
    }
}
