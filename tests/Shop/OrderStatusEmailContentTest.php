<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Notification\OrderStatusEmailContent;
use PHPUnit\Framework\TestCase;

final class OrderStatusEmailContentTest extends TestCase
{
    public function testOnHoldMentionsTheFormattedDeadlineWhenKnown(): void
    {
        $content = new OrderStatusEmailContent('on_hold', '1636', '30/09/2026', 10);
        $lines = $content->messageLines();

        self::assertCount(3, $lines);
        self::assertStringContainsString('commande n°1636', $lines[0]);
        self::assertStringContainsString('au plus tard le 30/09/2026 inclus, soit sous 10 jours ouvrés', $lines[1]);
    }

    public function testOnHoldFallsBackToTheDelayWhenNoDeadline(): void
    {
        $content = new OrderStatusEmailContent('on_hold', '1636', '', 10);

        self::assertStringContainsString('doit être reçu sous 10 jours ouvrés', $content->messageLines()[1]);
    }

    public function testReadyForPickupUsesThePickupAddress(): void
    {
        $content = new OrderStatusEmailContent('ready_for_pickup', '1636', pickupAddress: '1 rue des Ruches, 37150 Luzillé');

        self::assertStringContainsString('à mon domicile, au 1 rue des Ruches, 37150 Luzillé', $content->messageLines()[1]);
    }

    public function testCancelledCarriesTheReason(): void
    {
        $content = new OrderStatusEmailContent('cancelled', '1636', cancellationReason: 'Règlement non reçu');

        self::assertSame('Motif : Règlement non reçu', $content->messageLines()[1]);
    }

    public function testCompletedThanksTheCustomer(): void
    {
        $content = new OrderStatusEmailContent('completed', '1636');

        self::assertSame('À bientôt chez LuziApi !', $content->messageLines()[2]);
    }

    public function testUnknownStatusHasNoLines(): void
    {
        self::assertSame([], (new OrderStatusEmailContent('whatever', '1636'))->messageLines());
    }

    public function testLabelAndClosingLine(): void
    {
        self::assertSame('Commande terminée', (new OrderStatusEmailContent('completed', '1'))->label());
        self::assertSame('Règlement en attente', (new OrderStatusEmailContent('on_hold', '1'))->label());
        self::assertSame('Votre commande', (new OrderStatusEmailContent('whatever', '1'))->label());

        self::assertStringContainsString('apiculture locale', (new OrderStatusEmailContent('completed', '1'))->closingLine());
        self::assertSame('Merci pour votre confiance.', (new OrderStatusEmailContent('whatever', '1'))->closingLine());
    }
}
