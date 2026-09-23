<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\AppliedVolumeDiscount;
use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountCommand;
use LuziApi\Shop\Application\Command\ApplyMissingVolumeDiscount\ApplyMissingVolumeDiscountHandler;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Shop\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Shop\Domain\Gateway\OrderVolumeDiscountWriter;
use LuziApi\Shop\Domain\Receipt\ReceiptEntryType;
use PHPUnit\Framework\TestCase;

/**
 * Réutilise les doubles partagés déclarés dans {@see ApplyThankYouDiscountHandlerTest}
 * (même namespace de test) : DiscountReceiptRepository, DiscountClock,
 * NullActivityRepository. Seul le stub du port de volume est propre à ce test.
 *
 * La correction de recette RÉCONCILIE le registre sur le total corrigé de la
 * commande (contre-passe = encaissé − total, borné à ≥ 0) : idempotente,
 * auto-réparante, sans sur-remboursement.
 */
final class ApplyMissingVolumeDiscountHandlerTest extends TestCase
{
    public function testCatchUpOnACollectedOrderCorrectsTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        // La commande 42 a été encaissée à 52,00 € (sans la remise de volume).
        $this->collect($receipts, $clock, 5_200);

        // Fee de −5 € posé, total corrigé à 47 €.
        $this->handler(new VolumeDiscountWriterStub(500, 4_700), $receipts, $clock)
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
        $this->collect($receipts, $clock, 5_000);

        // Le delta manquant (−3 €) porte le total à 47 € : seul ce delta est contre-passé.
        $this->handler(new VolumeDiscountWriterStub(300, 4_700), $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        self::assertCount(2, $receipts->entries);
        self::assertSame(-300, $receipts->entries[1]->amount->cents());
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testRetryHealsWhenFeeWasAppliedButReceiptWasNotCorrected(): void
    {
        // Cas de reprise après un échec : le fee est déjà posé (donc plus rien à
        // appliquer, appliedCents = 0), mais le registre est resté à 52 €. Rejouer
        // DOIT terminer la correction de recette (auto-réparation).
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        $this->collect($receipts, $clock, 5_200);

        $this->handler(new VolumeDiscountWriterStub(0, 4_700), $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        self::assertCount(2, $receipts->entries);
        self::assertSame(-500, $receipts->entries[1]->amount->cents());
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testDoesNotOverRefundWhenTheReceiptIsAlreadyCorrect(): void
    {
        // La recette avait déjà été corrigée à 47 € (réconciliation manuelle) mais le
        // fee manquait encore côté commande. On pose le fee SANS re-rembourser.
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        $this->collect($receipts, $clock, 4_700);

        $this->handler(new VolumeDiscountWriterStub(500, 4_700), $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        // Aucune contre-passe : la seule écriture reste l'encaissement initial.
        self::assertCount(1, $receipts->entries);
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testCatchUpOnANotYetCollectedOrderDoesNotTouchTheRegister(): void
    {
        $receipts = new DiscountReceiptRepository();

        $this->handler(new VolumeDiscountWriterStub(500, 4_700), $receipts, new DiscountClock())
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        self::assertSame([], $receipts->entries);
    }

    public function testNothingMissingLeavesEverythingUntouched(): void
    {
        $receipts = new DiscountReceiptRepository();
        $clock = new DiscountClock();
        $this->collect($receipts, $clock, 4_700);
        $writer = new VolumeDiscountWriterStub(0, 4_700);

        $this->handler($writer, $receipts, $clock)
            ->handle(new ApplyMissingVolumeDiscountCommand(42, 7));

        // Rien ajouté ET registre déjà aligné : la recette reste à 47,00 €.
        self::assertSame(1, $writer->calls);
        self::assertCount(1, $receipts->entries);
        self::assertSame(4_700, $receipts->netTotalsByOrderIds([42])[42]);
    }

    public function testRejectsAnInvalidOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(new VolumeDiscountWriterStub(500, 4_700), new DiscountReceiptRepository(), new DiscountClock())
            ->handle(new ApplyMissingVolumeDiscountCommand(0, 7));
    }

    private function collect(DiscountReceiptRepository $receipts, DiscountClock $clock, int $cents): void
    {
        (new RecordReceiptHandler($receipts, $clock))->handle(new RecordReceiptCommand(
            42,
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Paris')),
            $cents,
            'cash',
            ReceiptEntryType::Collection,
            'Encaissement',
            7,
        ));
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

    public function __construct(private int $appliedCents, private int $orderTotalCents)
    {
    }

    public function applyMissing(int $orderId): AppliedVolumeDiscount
    {
        ++$this->calls;

        return new AppliedVolumeDiscount($orderId, (string) $orderId, $this->appliedCents, 'cash', 5, $this->orderTotalCents);
    }
}
