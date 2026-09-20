<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\AuditReceiptDrift;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Domain\Clock;
use LuziApi\Shop\Domain\Receipt\OrphanReceipt;
use LuziApi\Shop\Domain\Receipt\ReceiptDriftAuditor;
use LuziApi\Shop\Domain\Receipt\ReceiptDriftReport;
use LuziApi\Shop\Domain\Receipt\ReceiptEntry;
use LuziApi\Shop\Domain\Receipt\ReceiptReconciliationProjector;
use LuziApi\Shop\Domain\Receipt\ReceiptRepository;
use LuziApi\Shop\Domain\Sales\OrderRepository;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shop\Domain\Shared\Money;

final readonly class AuditReceiptDriftHandler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private OrderRepository $orders,
        private ReceiptReconciliationProjector $reconciliationProjector,
        private ReceiptDriftAuditor $auditor,
        private Clock $clock,
    ) {
    }

    public function handle(AuditReceiptDriftQuery $query): ReceiptDriftReport
    {
        [$start, $end] = $this->period($query, $this->clock->timezone());

        // Côté commandes : montant attendu vs enregistré (le projecteur ne garde que
        // les commandes commercialement valides dont l'écart est non nul).
        $orders = $this->orders->createdBetween($start, $end);
        $orderIds = array_map(static fn (OrderSnapshot $order): int => $order->id, $orders);
        $reconciliations = $this->reconciliationProjector->project(
            $orders,
            $this->receipts->netTotalsByOrderIds($orderIds),
        );

        return $this->auditor->audit($reconciliations, $this->orphans($start, $end));
    }

    /**
     * Recettes rattachées à une commande disparue : on part des identifiants de
     * commande cités par les recettes de la période, on retire ceux qui existent
     * encore, et on ne garde que ceux dont le net est non nul (une contre-écriture
     * ramenant à zéro n'est pas une dérive).
     *
     * @return list<OrphanReceipt>
     */
    private function orphans(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $referenced = array_values(array_unique(array_filter(
            array_map(
                static fn (ReceiptEntry $entry): ?int => $entry->orderId,
                $this->receipts->occurredBetween($start, $end),
            ),
            static fn (?int $orderId): bool => null !== $orderId,
        )));

        $missingOrderIds = array_values(array_diff($referenced, $this->orders->existingOrderIds($referenced)));

        $orphans = [];
        foreach ($this->receipts->netTotalsByOrderIds($missingOrderIds) as $orderId => $cents) {
            if (0 !== $cents) {
                $orphans[] = new OrphanReceipt($orderId, new Money($cents));
            }
        }

        return $orphans;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function period(AuditReceiptDriftQuery $query, DateTimeZone $timezone): array
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
