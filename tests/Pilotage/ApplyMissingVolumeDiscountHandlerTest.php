<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount\AppliedVolumeDiscount;
use LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountCommand;
use LuziApi\Pilotage\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountHandler;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\OrderVolumeDiscountWriter;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use PHPUnit\Framework\TestCase;

/**
 * Réutilise les doubles partagés déclarés dans {@see ApplyThankYouDiscountHandlerTest}
 * (même namespace de test) : DiscountReceiptRepository, DiscountClock,
 * NullActivityRepository. Seul le stub du port de volume est propre à ce test.
 */
final class ApplyMissingVolumeDiscountHandlerTest extends TestCase
{
    public function testCatchUpOnACollectedOrderCorrectsTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        // La commande 42 a été encaissée à 52,00 € (sans la remise de volume).
        (new RecordReceiptHandler($receipts, $clock))->handle(new RecordReceiptCommand(
            42,
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Paris')),
            5_200,
            'cash',
            ReceiptEntryType::Collection,
            'Encaissement',
            7,
        ));

        $this->handler(new VolumeDiscountWriterStub(500), $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        // Une contre-écriture Refund de −5,00 € ramène la recette à 47,00 €.
        self::assertCount(2, $receipts->entries);
        self::assertSame(ReceiptEntryType::Refund, $receipts->entries[1]->type);
        self::assertSame(-500, $receipts->entries[1]->amount->cents());
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testPartialCatchUpOnlyCorrectsTheMissingDelta(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        // La commande 42 portait déjà −2 € de remise et fut encaissée à 50,00 €.
        (new RecordReceiptHandler($receipts, $clock))->handle(new RecordReceiptCommand(
            42,
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Paris')),
            5_000,
            'cash',
            ReceiptEntryType::Collection,
            'Encaissement',
            7,
        ));

        // Seul le delta manquant (3,00 €) est appliqué et contre-passé.
        $this->handler(new VolumeDiscountWriterStub(300), $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        self::assertCount(2, $receipts->entries);
        self::assertSame(-300, $receipts->entries[1]->amount->cents());
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testCatchUpOnANotYetCollectedOrderDoesNotTouchTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();

        $this->handler(new VolumeDiscountWriterStub(500), $receipts, new DiscountClock())
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        self::assertSame([], $receipts->entries);
    }

    public function testNothingMissingLeavesEverythingUntouched(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        (new RecordReceiptHandler($receipts, $clock))->handle(new RecordReceiptCommand(
            42,
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Paris')),
            4_700,
            'cash',
            ReceiptEntryType::Collection,
            'Encaissement',
            7,
        ));
        $writer = new VolumeDiscountWriterStub(0);

        $this->handler($writer, $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        // Rien ajouté : la remise était déjà correcte, la recette reste à 47,00 €.
        self::assertSame(1, $writer->calls);
        self::assertCount(1, $receipts->entries);
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testRejectsAnInvalidOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(new VolumeDiscountWriterStub(500), new DiscountReceiptRepository(), new DiscountClock())
            ->handle(new ApplyMissingVolumeDiscountCommand(0, 7));
    }

    private function handler(
        OrderVolumeDiscountWriter $orders,
        DiscountReceiptRepository $receipts,
        DiscountClock $clock,
    ): ApplyMissingVolumeDiscountHandler {
        return new ApplyMissingVolumeDiscountHandler(
            $orders,
            new RecordReceiptHandler($receipts, $clock),
            $receipts,
            new ActivityRecorder(new NullActivityRepository(), $clock),
            $clock,
        );
    }
}

final class VolumeDiscountWriterStub implements OrderVolumeDiscountWriter
{
    public int $calls = 0;

    public function __construct(private int $appliedCents)
    {
    }

    public function applyMissing(int $orderId): AppliedVolumeDiscount
    {
        ++$this->calls;

        return new AppliedVolumeDiscount($orderId, (string) $orderId, $this->appliedCents, 'cash', 5);
    }
}
