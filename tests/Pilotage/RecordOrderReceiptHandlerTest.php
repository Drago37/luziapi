<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordOrderReceipt\RecordOrderReceiptHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use PHPUnit\Framework\TestCase;

final class RecordOrderReceiptHandlerTest extends TestCase
{
    public function testRecordsTheFullAmountWhenNothingIsRecordedYet(): void
    {
        $repository = new OrderReceiptRepositoryInMemory();

        $entry = $this->handler($repository)->handle($this->command(42, 2_500));

        self::assertNotNull($entry);
        self::assertSame(2_500, $entry->amount->cents());
        self::assertSame(ReceiptEntryType::Collection, $entry->type);
        self::assertSame(42, $entry->orderId);
    }

    public function testIsIdempotentWhenAlreadyFullyRecorded(): void
    {
        $repository = new OrderReceiptRepositoryInMemory();
        $handler = $this->handler($repository);

        $handler->handle($this->command(42, 2_500));

        self::assertNull($handler->handle($this->command(42, 2_500)));
        self::assertSame(2_500, $repository->netTotalsByOrderIds([42])[42] ?? 0);
    }

    public function testRecordsOnlyTheMissingBalance(): void
    {
        $repository = new OrderReceiptRepositoryInMemory();
        $handler = $this->handler($repository);

        $handler->handle($this->command(42, 1_000));
        $entry = $handler->handle($this->command(42, 2_500));

        self::assertNotNull($entry);
        self::assertSame(1_500, $entry->amount->cents());
    }

    public function testIgnoresNonPositiveExpectedAmount(): void
    {
        $repository = new OrderReceiptRepositoryInMemory();

        self::assertNull($this->handler($repository)->handle($this->command(42, 0)));
    }

    private function handler(ReceiptRepository $repository): RecordOrderReceiptHandler
    {
        return new RecordOrderReceiptHandler(
            $repository,
            new RecordReceiptHandler($repository, new OrderReceiptClock()),
        );
    }

    private function command(int $orderId, int $expectedCents): RecordOrderReceiptCommand
    {
        return new RecordOrderReceiptCommand(
            $orderId,
            $expectedCents,
            'cash',
            new DateTimeImmutable('2026-09-08 09:00:00', new DateTimeZone('Europe/Paris')),
            0,
            'Encaissement — commande n°' . $orderId,
        );
    }
}

final class OrderReceiptClock implements Clock
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

final class OrderReceiptRepositoryInMemory implements ReceiptRepository
{
    /** @var list<ReceiptEntry> */
    private array $entries = [];

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
        return false;
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [];
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
