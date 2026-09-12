<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\AppliedThankYouDiscount;
use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountCommand;
use LuziApi\Pilotage\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\OrderDiscountWriter;
use LuziApi\Pilotage\Domain\Activity\ActivityEntry;
use LuziApi\Pilotage\Domain\Activity\ActivityFilter;
use LuziApi\Pilotage\Domain\Activity\ActivityRepository;
use LuziApi\Pilotage\Domain\Activity\NewActivityEntry;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;
use PHPUnit\Framework\TestCase;

final class ApplyThankYouDiscountHandlerTest extends TestCase
{
    public function testDiscountOnACollectedOrderCorrectsTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        // La commande 42 a déjà été encaissée (24,00 €).
        (new RecordReceiptHandler($receipts, $clock))->handle(new RecordReceiptCommand(
            42,
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Paris')),
            2_400,
            'cash',
            ReceiptEntryType::Collection,
            'Encaissement',
            7,
        ));

        $this->handler(new OrderDiscountWriterStub(500), $receipts, $clock)
            ->handle(new ApplyThankYouDiscountCommand(42, ThankYouDiscount::percent(10), 7));

        // Une contre-écriture Refund de -5,00 € corrige la recette : net 19,00 €.
        self::assertCount(2, $receipts->entries);
        self::assertSame(ReceiptEntryType::Refund, $receipts->entries[1]->type);
        self::assertSame(-500, $receipts->entries[1]->amount->cents());
        self::assertSame(1_900, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testDiscountOnANotYetCollectedOrderDoesNotTouchTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();

        $this->handler(new OrderDiscountWriterStub(500), $receipts, new DiscountClock())
            ->handle(new ApplyThankYouDiscountCommand(42, ThankYouDiscount::amount(500), 7));

        self::assertSame([], $receipts->entries);
    }

    public function testNoDiscountAppliedLeavesEverythingUntouched(): void
    {
        $receipts = new DiscountReceiptRepository();
        $writer = new OrderDiscountWriterStub(0);

        $this->handler($writer, $receipts, new DiscountClock())
            ->handle(new ApplyThankYouDiscountCommand(42, ThankYouDiscount::amount(500), 7));

        self::assertSame(1, $writer->calls);
        self::assertSame([], $receipts->entries);
    }

    private function handler(
        OrderDiscountWriter $orders,
        DiscountReceiptRepository $receipts,
        DiscountClock $clock,
    ): ApplyThankYouDiscountHandler {
        return new ApplyThankYouDiscountHandler(
            $orders,
            new RecordReceiptHandler($receipts, $clock),
            $receipts,
            new ActivityRecorder(new NullActivityRepository(), $clock),
            $clock,
        );
    }
}

final class OrderDiscountWriterStub implements OrderDiscountWriter
{
    public int $calls = 0;

    public function __construct(private int $discountCents)
    {
    }

    public function apply(int $orderId, ThankYouDiscount $discount): AppliedThankYouDiscount
    {
        ++$this->calls;

        return new AppliedThankYouDiscount($orderId, (string) $orderId, $this->discountCents, 'cash');
    }
}

final class DiscountClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09 10:00:00', $this->timezone());
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }
}

final class DiscountReceiptRepository implements ReceiptRepository
{
    /** @var list<ReceiptEntry> */
    public array $entries = [];

    public function add(NewReceiptEntry $entry): ReceiptEntry
    {
        $stored = new ReceiptEntry(
            count($this->entries) + 1,
            count($this->entries) + 1,
            $entry->orderId,
            $entry->occurredAt,
            $entry->amount,
            $entry->paymentMethod,
            $entry->type,
            $entry->description,
            $entry->reversalOfId,
            $entry->createdBy,
            $entry->createdAt,
        );
        $this->entries[] = $stored;

        return $stored;
    }

    public function find(int $id): ?ReceiptEntry
    {
        return $this->entries[$id - 1] ?? null;
    }

    public function hasReversalFor(int $entryId): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->reversalOfId === $entryId) {
                return true;
            }
        }

        return false;
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ReceiptEntry $entry): bool => $entry->occurredAt >= $start && $entry->occurredAt <= $end,
        ));
    }

    public function netTotalsByOrderIds(array $orderIds): array
    {
        $totals = [];
        foreach ($this->entries as $entry) {
            if (null !== $entry->orderId && in_array($entry->orderId, $orderIds, true)) {
                $totals[$entry->orderId] = ($totals[$entry->orderId] ?? 0) + $entry->amount->cents();
            }
        }

        return $totals;
    }

    public function deleteByOrderId(int $orderId): int
    {
        $before = count($this->entries);
        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (ReceiptEntry $entry): bool => $entry->orderId !== $orderId,
        ));

        return $before - count($this->entries);
    }
}

final class NullActivityRepository implements ActivityRepository
{
    public function add(NewActivityEntry $entry): ActivityEntry
    {
        throw new \RuntimeException('not needed for these tests');
    }

    public function search(ActivityFilter $filter): array
    {
        return [];
    }
}
