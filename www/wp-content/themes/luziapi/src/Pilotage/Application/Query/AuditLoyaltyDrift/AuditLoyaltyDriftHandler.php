<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\AuditLoyaltyDrift;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\EligiblePotReader;
use LuziApi\Pilotage\Application\Port\LoyaltyLedgerReader;
use LuziApi\Pilotage\Domain\Loyalty\LoyaltyCreditGap;
use LuziApi\Pilotage\Domain\Loyalty\LoyaltyDriftReport;
use LuziApi\Pilotage\Domain\Loyalty\OrphanLoyaltyCredit;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;

/**
 * Audite la dérive du programme de fidélité (lecture seule) : commandes « Terminée »
 * admissibles jamais créditées (trous de crédit, sur la période) et écritures encore
 * positives rattachées à une commande disparue (orphelines, tout le journal).
 */
final readonly class AuditLoyaltyDriftHandler
{
    public function __construct(
        private OrderRepository $orders,
        private EligiblePotReader $eligiblePots,
        private LoyaltyLedgerReader $ledger,
        private Clock $clock,
    ) {
    }

    public function handle(AuditLoyaltyDriftQuery $query): LoyaltyDriftReport
    {
        [$start, $end] = $this->period($query, $this->clock->timezone());

        $completed = array_values(array_filter(
            $this->orders->createdBetween($start, $end),
            static fn (OrderSnapshot $order): bool => 'completed' === $order->status,
        ));
        $completedIds = array_map(static fn (OrderSnapshot $order): int => $order->id, $completed);

        $eligible = $this->eligiblePots->eligiblePotsByOrderIds($completedIds);
        $credited = array_fill_keys($this->ledger->sourceOrderIds(), true);

        $creditGaps = [];
        foreach ($completed as $order) {
            $pots = $eligible[$order->id] ?? 0;
            if ($pots > 0 && ! isset($credited[$order->id])) {
                $creditGaps[] = new LoyaltyCreditGap($order->id, $order->number, $pots);
            }
        }

        return new LoyaltyDriftReport($creditGaps, $this->orphans());
    }

    /**
     * Crédits rattachés à une commande disparue : on part de toutes les commandes
     * citées par le journal, on retire celles qui existent encore, et on ne garde
     * que celles dont le solde net de pots reste positif (une contre-passation
     * ramenant à zéro n'est pas une dérive). Toujours sur tout l'historique.
     *
     * @return list<OrphanLoyaltyCredit>
     */
    private function orphans(): array
    {
        $referenced = $this->ledger->sourceOrderIds();
        if ([] === $referenced) {
            return [];
        }

        $gone = array_values(array_diff($referenced, $this->orders->existingOrderIds($referenced)));

        $orphans = [];
        foreach ($this->ledger->netPotsByOrderIds($gone) as $orderId => $netPots) {
            if ($netPots > 0) {
                $orphans[] = new OrphanLoyaltyCredit($orderId, $netPots);
            }
        }

        return $orphans;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function period(AuditLoyaltyDriftQuery $query, DateTimeZone $timezone): array
    {
        if (null !== $query->year) {
            return [
                new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $query->year), $timezone),
                new DateTimeImmutable(sprintf('%d-12-31 23:59:59', $query->year), $timezone),
            ];
        }

        $now = $this->clock->now();
        $first = $this->orders->firstOrderDate() ?? $now;

        return [$first->setTime(0, 0, 0), $now];
    }
}
