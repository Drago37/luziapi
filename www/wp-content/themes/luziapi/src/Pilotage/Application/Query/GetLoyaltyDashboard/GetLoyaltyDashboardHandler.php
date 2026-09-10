<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetLoyaltyDashboard;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;

/**
 * Construit le récapitulatif de fidélité par client et les classements pour une
 * année civile : pots achetés, pots offerts et remise remerciement, agrégés
 * depuis les commandes **terminées** de l'année.
 */
final readonly class GetLoyaltyDashboardHandler
{
    public function __construct(
        private OrderRepository $orders,
        private CustomerHistoryProjector $projector,
        private LoyaltyEconomicsReader $economics,
        private Clock $clock,
    ) {
    }

    public function handle(GetLoyaltyDashboardQuery $query): LoyaltyDashboardView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $start = $this->orders->firstOrderDate() ?? $this->clock->now();
        $orders = $this->orders->createdBetween($start, $this->clock->now());
        $profiles = $this->projector->project($orders);

        $availableYears = $this->availableYears($orders, $currentYear);
        $year = $query->year ?? $currentYear;
        if (! in_array($year, $availableYears, true)) {
            $year = $availableYears[0] ?? $currentYear;
        }

        $rows = [];
        foreach ($profiles as $profile) {
            $orderIds = $this->completedOrderIdsForYear($profile, $year);
            if ([] === $orderIds) {
                continue;
            }
            $economics = $this->economics->forOrderIds($orderIds);
            if ($economics['potsBought'] <= 0 && $economics['offeredPots'] <= 0 && $economics['discountCents'] <= 0) {
                continue;
            }
            $rows[] = new LoyaltyCustomerRow(
                $profile->id,
                $this->displayName($profile),
                $profile->city,
                $economics['potsBought'],
                $economics['offeredPots'],
                $economics['discountCents'],
            );
        }

        $byPots = $this->sorted($rows, static fn (LoyaltyCustomerRow $r): int => $r->potsBought);

        return new LoyaltyDashboardView(
            year: $year,
            availableYears: $availableYears,
            customers: $byPots,
            topBuyers: array_slice($byPots, 0, $query->topSize),
            topBenefited: array_slice($this->topBy($rows, static fn (LoyaltyCustomerRow $r): int => $r->offeredPots), 0, $query->topSize),
            topDiscounts: array_slice($this->topBy($rows, static fn (LoyaltyCustomerRow $r): int => $r->discountCents), 0, $query->topSize),
            totalCustomers: count($rows),
            totalPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->potsBought, $rows)),
            totalOfferedPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->offeredPots, $rows)),
            totalDiscountCents: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->discountCents, $rows)),
        );
    }

    /**
     * @param list<OrderSnapshot> $orders
     *
     * @return list<int>
     */
    private function availableYears(array $orders, int $currentYear): array
    {
        $years = [$currentYear => true];
        foreach ($orders as $order) {
            $years[(int) $this->orderDate($order)->format('Y')] = true;
        }
        $years = array_keys($years);
        rsort($years);

        return $years;
    }

    /**
     * @return list<int>
     */
    private function completedOrderIdsForYear(CustomerProfile $profile, int $year): array
    {
        $ids = [];
        foreach ($profile->orders as $order) {
            if ('completed' === $order->status && (int) $this->orderDate($order)->format('Y') === $year) {
                $ids[] = $order->id;
            }
        }

        return $ids;
    }

    private function orderDate(OrderSnapshot $order): \DateTimeImmutable
    {
        return $order->paidAt ?? $order->createdAt;
    }

    /**
     * @param list<LoyaltyCustomerRow>           $rows
     * @param callable(LoyaltyCustomerRow): int $metric
     *
     * @return list<LoyaltyCustomerRow>
     */
    private function topBy(array $rows, callable $metric): array
    {
        return array_values(array_filter($this->sorted($rows, $metric), static fn (LoyaltyCustomerRow $r): bool => $metric($r) > 0));
    }

    /**
     * @param list<LoyaltyCustomerRow>           $rows
     * @param callable(LoyaltyCustomerRow): int $metric
     *
     * @return list<LoyaltyCustomerRow>
     */
    private function sorted(array $rows, callable $metric): array
    {
        usort($rows, static fn (LoyaltyCustomerRow $a, LoyaltyCustomerRow $b): int => $metric($b) <=> $metric($a));

        return $rows;
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
