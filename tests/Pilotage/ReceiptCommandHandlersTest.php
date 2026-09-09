<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Command\ReverseReceipt\ReverseReceiptCommand;
use LuziApi\Pilotage\Application\Command\ReverseReceipt\ReverseReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Receipt\NewReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use PHPUnit\Framework\TestCase;

final class ReceiptCommandHandlersTest extends TestCase
{
    public function testRefundIsStoredAsANegativeImmutableEntry(): void
    {
        $repository = new ReceiptRepositoryForHandlerTest();

        $entry = (new RecordReceiptHandler($repository, new ReceiptHandlerClock()))->handle(
            new RecordReceiptCommand(
                42,
                new DateTimeImmutable('2026-09-08 15:00:00'),
                1_250,
                'cash',
                ReceiptEntryType::Refund,
                'Pot retourné',
                7,
            ),
        );

        self::assertSame(-1_250, $entry->amount->cents());
        self::assertSame(ReceiptEntryType::Refund, $entry->type);
        self::assertNull($entry->reversalOfId);
    }

    public function testCorrectionCreatesACounterEntryAndCannotBeRepeated(): void
    {
        $repository = new ReceiptRepositoryForHandlerTest();
        $clock = new ReceiptHandlerClock();
        $original = (new RecordReceiptHandler($repository, $clock))->handle(
            new RecordReceiptCommand(
                42,
                new DateTimeImmutable('2026-09-08 15:00:00'),
                2_000,
                'cheque',
                ReceiptEntryType::Collection,
                'Règlement',
                7,
            ),
        );
        $handler = new ReverseReceiptHandler($repository, $clock);

        $reversal = $handler->handle(new ReverseReceiptCommand($original->id, 'Erreur de montant', 7));

        self::assertSame(-2_000, $reversal->amount->cents());
        self::assertSame($original->id, $reversal->reversalOfId);
        self::assertSame(ReceiptEntryType::Reversal, $reversal->type);

        $this->expectException(InvalidArgumentException::class);
        $handler->handle(new ReverseReceiptCommand($original->id, 'Deuxième correction', 7));
    }
}

final class ReceiptHandlerClock implements Clock
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

final class ReceiptRepositoryForHandlerTest implements ReceiptRepository
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
        foreach ($this->entries as $entry) {
            if ($entry->reversalOfId === $entryId) {
                return true;
            }
        }

        return false;
    }

    public function occurredBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [];
    }

    public function netTotalsByOrderIds(array $orderIds): array
    {
        return [];
    }
}
