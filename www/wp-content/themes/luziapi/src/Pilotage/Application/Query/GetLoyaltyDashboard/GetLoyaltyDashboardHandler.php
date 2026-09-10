<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;

/**
 * Construit le récapitulatif de fidélité par client et les classements, en
 * combinant le journal de fidélité (pots achetés, avantages) et l'économie des
 * commandes (remises remerciement, pots offerts).
 */
final readonly class GetLoyaltyDashboardHandler
{
    public function __construct(
        private OrderRepository $orders,
        private CustomerHistoryProjector $projector,
        private LoyaltyEconomicsReader $economics,
        private Clock $clock,
        private ?GetCustomerLoyaltyHandler $loyalty = null,
    ) {
    }

    public function handle(GetLoyaltyDashboardQuery $query): LoyaltyDashboardView
    {
        if (null === $this->loyalty) {
            return LoyaltyDashboardView::empty();
        }

        $start = $this->orders->firstOrderDate() ?? $this->clock->now();
        $orders = $this->orders->createdBetween($start, $this->clock->now());
        $profiles = $this->projector->project($orders);

        $rows = [];
        foreach ($profiles as $profile) {
            $loyalty = $this->loyalty->handle(new GetCustomerLoyaltyQuery($profile->identityIds));
            $economics = $this->economics->forOrderIds($this->orderIds($profile));
            if ($loyalty->netPots <= 0 && $economics['offeredPots'] <= 0 && $economics['discountCents'] <= 0) {
                continue; // aucun mouvement de fidélité : hors récap
            }
            $rows[] = new LoyaltyCustomerRow(
                $profile->id,
                $this->displayName($profile),
                $profile->city,
                $loyalty->netPots,
                $loyalty->rewardsAvailable,
                $economics['offeredPots'],
                $economics['discountCents'],
            );
        }

        $byPots = $this->sorted($rows, static fn (LoyaltyCustomerRow $r): int => $r->netPots);

        return new LoyaltyDashboardView(
            customers: $byPots,
            topBuyers: array_slice($byPots, 0, $query->topSize),
            topBenefited: array_slice(
                array_values(array_filter(
                    $this->sorted($rows, static fn (LoyaltyCustomerRow $r): int => $r->offeredPots),
                    static fn (LoyaltyCustomerRow $r): bool => $r->offeredPots > 0,
                )),
                0,
                $query->topSize,
            ),
            topDiscounts: array_slice(
                array_values(array_filter(
                    $this->sorted($rows, static fn (LoyaltyCustomerRow $r): int => $r->discountCents),
                    static fn (LoyaltyCustomerRow $r): bool => $r->discountCents > 0,
                )),
                0,
                $query->topSize,
            ),
            totalCustomers: count($rows),
            totalPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->netPots, $rows)),
            totalOfferedPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->offeredPots, $rows)),
            totalDiscountCents: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->discountCents, $rows)),
            totalRewardsAvailable: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->rewardsAvailable, $rows)),
        );
    }

    /**
     * @param list<LoyaltyCustomerRow>            $rows
     * @param callable(LoyaltyCustomerRow): int $metric
     *
     * @return list<LoyaltyCustomerRow>
     */
    private function sorted(array $rows, callable $metric): array
    {
        usort($rows, static fn (LoyaltyCustomerRow $a, LoyaltyCustomerRow $b): int => $metric($b) <=> $metric($a));

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function orderIds(CustomerProfile $profile): array
    {
        return array_map(static fn (OrderSnapshot $order): int => $order->id, $profile->orders);
    }

    private function displayName(CustomerProfile $profile): string
    {
        if ('' !== $profile->name) {
            return $profile->name;
        }
        $contact = $profile->primaryPhone() ?: $profile->primaryEmail();

        return '' !== $contact ? $contact : 'Client de passage';
    }
}
