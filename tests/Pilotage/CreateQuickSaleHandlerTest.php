<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreatedQuickSale;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleCommand;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\CreateQuickSaleHandler;
use LuziApi\Pilotage\Application\Command\CreateQuickSale\QuickSaleLine;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\QuickSaleOrderWriter;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use PHPUnit\Framework\TestCase;

final class CreateQuickSaleHandlerTest extends TestCase
{
    public function testPaidSaleCreatesTheOrderThenItsReceipt(): void
    {
        $orders = new QuickSaleOrderWriterSpy();
        $receipts = new ReceiptRepositoryInMemory();
        $handler = $this->handler($orders, $receipts);

        $created = $handler->handle($this->command(paid: true));

        self::assertSame(42, $created->orderId);
        self::assertTrue($created->receiptRecorded);
        self::assertSame(1, $orders->calls);
        self::assertSame(42, $orders->markedReceiptOrderId);
        self::assertCount(1, $receipts->entries);
        self::assertSame(1_990, $receipts->entries[0]->amount->cents());
        self::assertSame(42, $receipts->entries[0]->orderId);
    }

    public function testUnpaidSaleDoesNotCreateAReceipt(): void
    {
        $orders = new QuickSaleOrderWriterSpy();
        $receipts = new ReceiptRepositoryInMemory();

        $created = $this->handler($orders, $receipts)->handle($this->command(paid: false));

        self::assertFalse($created->receiptRecorded);
        self::assertSame([], $receipts->entries);
    }

    public function testReplayedRequestReturnsExistingOrderWithoutCreatingAnotherReceipt(): void
    {
        $orders = new QuickSaleOrderWriterSpy();
        $orders->alreadyExists = true;
        $receipts = new ReceiptRepositoryInMemory();

        $created = $this->handler($orders, $receipts)->handle($this->command(paid: true));

        self::assertTrue($created->alreadyExisted);
        self::assertSame(1, $orders->calls);
        self::assertSame([], $receipts->entries);
        self::assertSame(0, $orders->markedReceiptOrderId);
    }

    public function testFutureSaleIsRejectedBeforeWooCommerceIsCalled(): void
    {
        $orders = new QuickSaleOrderWriterSpy();
        $receipts = new ReceiptRepositoryInMemory();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->handler($orders, $receipts)->handle($this->command(
                paid: true,
                occurredAt: new DateTimeImmutable('2026-09-10 10:00:00', new DateTimeZone('Europe/Paris')),
            ));
        } finally {
            self::assertSame(0, $orders->calls);
        }
    }

    public function testInvalidPhoneIsRejectedBeforeWooCommerceIsCalled(): void
    {
        $orders = new QuickSaleOrderWriterSpy();
        $receipts = new ReceiptRepositoryInMemory();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->handler($orders, $receipts)->handle($this->command(paid: true, phone: '123'));
        } finally {
            self::assertSame(0, $orders->calls);
        }
    }

    private function handler(QuickSaleOrderWriter $orders, ReceiptRepository $receipts): CreateQuickSaleHandler
    {
        $clock = new FixedPilotageClock();

        return new CreateQuickSaleHandler($orders, new RecordReceiptHandler($receipts, $clock), $clock);
    }

    private function command(
        bool $paid,
        string $phone = '06 12 34 56 78',
        ?DateTimeImmutable $occurredAt = null,
    ): CreateQuickSaleCommand {
        return new CreateQuickSaleCommand(
            [new QuickSaleLine(12, 2)],
            'Camille Martin',
            'camille@example.test',
            $phone,
            '',
            '',
            '',
            'market',
            'cash',
            'immediate',
            $paid,
            false,
            $occurredAt ?? new DateTimeImmutable('2026-09-09 09:30:00', new DateTimeZone('Europe/Paris')),
            7,
            '6f9619ff-8b86-d011-b42d-00cf4fc964ff',
        );
    }
}

final class FixedPilotageClock implements Clock
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

final class QuickSaleOrderWriterSpy implements QuickSaleOrderWriter
{
    public int $calls = 0;
    public int $markedReceiptOrderId = 0;
    public bool $alreadyExists = false;

    public function create(CreateQuickSaleCommand $command): CreatedQuickSale
    {
        ++$this->calls;

        return new CreatedQuickSale(42, '1042', 1_990, false, $this->alreadyExists);
    }

    public function markReceiptRecorded(int $orderId): void
    {
        $this->markedReceiptOrderId = $orderId;
    }
}

final class ReceiptRepositoryInMemory implements ReceiptRepository
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
}
