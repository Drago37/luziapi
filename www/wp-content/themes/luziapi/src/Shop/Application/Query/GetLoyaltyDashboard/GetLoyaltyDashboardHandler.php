<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\GetLoyaltyDashboard;

use LuziApi\Shop\Application\Port\Clock;
use LuziApi\Shop\Application\Port\LoyaltyEconomicsReader;
use LuziApi\Shop\Application\Port\LoyaltyRewardsReader;
use LuziApi\Shop\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Shop\Domain\Customer\CustomerProfile;
use LuziApi\Shop\Domain\Sales\OrderRepository;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;

/**
 * Construit le récapitulatif de fidélité par client et les classements pour une
 * période (une année, ou toutes les années), à partir des commandes
 * **terminées**. Fournit aussi les totaux cumulés toutes années pour un encart
 * de synthèse toujours visible, dont le **passif** = avantages fidélité dus mais
 * non encore réclamés (pots offerts que la boutique devra honorer).
 */
final readonly class GetLoyaltyDashboardHandler
{
    /**
     * @param LoyaltyRewardsReader|null $rewards source des avantages disponibles par
     *                                           client (passif) ; `null` = passif à 0
     */
    public function __construct(
        private OrderRepository $orders,
        private CustomerHistoryProjector $projector,
        private LoyaltyEconomicsReader $economics,
        private Clock $clock,
        private ?LoyaltyRewardsReader $rewards = null,
    ) {
    }

    public function handle(GetLoyaltyDashboardQuery $query): LoyaltyDashboardView
    {
        $currentYear = (int) $this->clock->now()->format('Y');
        $start = $this->orders->firstOrderDate() ?? $this->clock->now();
        $orders = $this->orders->createdBetween($start, $this->clock->now());
        $profiles = $this->projector->project($orders);

        $availableYears = $this->availableYears($orders, $currentYear);
        [$periodYears, $periodKey, $periodLabel] = $this->resolvePeriod($query->period, $availableYears);
        $periodSet = array_fill_keys($periodYears, true);

        $rows = [];
        $grandCustomers = 0;
        $grandPots = 0;
        $grandOffered = 0;
        $grandDiscount = 0;

        foreach ($profiles as $profile) {
            // Cumul toutes années (encart de synthèse).
            $allIds = $this->completedOrderIds($profile, null);
            if ([] !== $allIds) {
                $all = $this->economics->forOrderIds($allIds);
                if ($all['potsBought'] > 0 || $all['offeredPots'] > 0 || $all['discountCents'] > 0) {
                    $grandCustomers++;
                    $grandPots += $all['potsBought'];
                    $grandOffered += $all['offeredPots'];
                    $grandDiscount += $all['discountCents'];
                }
            }

            // Période sélectionnée.
            $periodIds = $this->completedOrderIds($profile, $periodSet);
            if ([] === $periodIds) {
                continue;
            }
            $economics = $this->economics->forOrderIds($periodIds);
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

        // Avantages disponibles par client (toutes années, les pots n'expirent pas) :
        // une seule requête sert et le passif cumulé et la liste « en cours ».
        $rewardsByCustomer = $this->availableRewardsByCustomer($profiles);
        $outstanding = $this->rewardsOutstanding($profiles, $rewardsByCustomer);

        return new LoyaltyDashboardView(
            grandRewardsOwed: array_sum($rewardsByCustomer),
            rewardsOutstanding: $outstanding,
            periodKey: $periodKey,
            periodLabel: $periodLabel,
            availableYears: $availableYears,
            customers: $byPots,
            topBuyers: array_slice($byPots, 0, $query->topSize),
            topBenefited: array_slice($this->topBy($rows, static fn (LoyaltyCustomerRow $r): int => $r->offeredPots), 0, $query->topSize),
            topDiscounts: array_slice($this->topBy($rows, static fn (LoyaltyCustomerRow $r): int => $r->discountCents), 0, $query->topSize),
            totalCustomers: count($rows),
            totalPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->potsBought, $rows)),
            totalOfferedPots: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->offeredPots, $rows)),
            totalDiscountCents: array_sum(array_map(static fn (LoyaltyCustomerRow $r): int => $r->discountCents, $rows)),
            grandCustomers: $grandCustomers,
            grandPots: $grandPots,
            grandOfferedPots: $grandOffered,
            grandDiscountCents: $grandDiscount,
        );
    }

    /**
     * Avantages fidélité disponibles (non réclamés) par client, tous millésimes (les
     * pots n'expirent pas), calculés sur les clés d'identité de chaque profil en une
     * requête. Le total est le **passif** du programme ; la liste non nulle alimente
     * « Pots offerts en cours ».
     *
     * @param list<CustomerProfile> $profiles
     *
     * @return array<string, int> customerId => avantages disponibles
     */
    private function availableRewardsByCustomer(array $profiles): array
    {
        if (! $this->rewards instanceof LoyaltyRewardsReader) {
            return [];
        }

        $keysByCustomer = [];
        foreach ($profiles as $profile) {
            if ([] !== $profile->identityIds) {
                $keysByCustomer[$profile->id] = $profile->identityIds;
            }
        }
        if ([] === $keysByCustomer) {
            return [];
        }

        return $this->rewards->availableRewardsByCustomer($keysByCustomer);
    }

    /**
     * Clients ayant au moins un avantage en cours, avec leur nom, triés décroissant.
     *
     * @param list<CustomerProfile> $profiles
     * @param array<string, int>    $rewardsByCustomer
     *
     * @return list<LoyaltyRewardHolder>
     */
    private function rewardsOutstanding(array $profiles, array $rewardsByCustomer): array
    {
        $profilesById = [];
        foreach ($profiles as $profile) {
            $profilesById[$profile->id] = $profile;
        }

        $holders = [];
        foreach ($rewardsByCustomer as $customerId => $available) {
            if ($available <= 0 || ! isset($profilesById[$customerId])) {
                continue;
            }
            $holders[] = new LoyaltyRewardHolder($customerId, $this->displayName($profilesById[$customerId]), $available);
        }
        usort($holders, static fn (LoyaltyRewardHolder $a, LoyaltyRewardHolder $b): int => $b->rewards <=> $a->rewards);

        return $holders;
    }

    /**
     * @param list<int> $availableYears
     *
     * @return array{0: list<int>, 1: string, 2: string}
     */
    private function resolvePeriod(?string $period, array $availableYears): array
    {
        if (null !== $period && 1 === preg_match('/^\d{4}$/', $period) && in_array((int) $period, $availableYears, true)) {
            return [[(int) $period], $period, 'Année ' . $period];
        }

        // Par défaut (et pour 'all') : toutes les années. Les pots n'expirent plus,
        // donc plus de fenêtre glissante « 2 dernières années ».
        return [$availableYears, 'all', 'Toutes les années'];
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
     * Ids des commandes terminées du client, filtrées sur un ensemble d'années
     * (`null` = toutes les années).
     *
     * @param array<int, true>|null $yearSet
     *
     * @return list<int>
     */
    private function completedOrderIds(CustomerProfile $profile, ?array $yearSet): array
    {
        $ids = [];
        foreach ($profile->orders as $order) {
            if ('completed' !== $order->status) {
                continue;
            }
            $year = (int) $this->orderDate($order)->format('Y');
            if (null === $yearSet || isset($yearSet[$year])) {
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
