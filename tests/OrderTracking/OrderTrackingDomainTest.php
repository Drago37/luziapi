<?php

declare(strict_types=1);

namespace LuziApi\Tests\OrderTracking;

use InvalidArgumentException;
use LuziApi\OrderTracking\Domain\OrderAccessCredentials;
use LuziApi\OrderTracking\Domain\PublicOrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTrackingDomainTest extends TestCase
{
    public function testCredentialsAreNormalizedWithoutPuttingTheEmailInTheOrderNumber(): void
    {
        $credentials = new OrderAccessCredentials(' #1042 ', ' Client@Example.COM ');

        self::assertSame('1042', $credentials->orderNumber);
        self::assertSame('client@example.com', $credentials->email);
    }

    #[DataProvider('invalidCredentials')]
    public function testInvalidCredentialsAreRejected(string $orderNumber, string $email): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OrderAccessCredentials($orderNumber, $email);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCredentials(): iterable
    {
        yield 'missing order' => ['', 'client@example.com'];
        yield 'non numeric order' => ['ABC-42', 'client@example.com'];
        yield 'invalid email' => ['42', 'not-an-email'];
        yield 'oversized order' => [str_repeat('1', 21), 'client@example.com'];
    }

    #[DataProvider('statuses')]
    public function testPublicStatusesAreWarmAndExplicit(
        string $status,
        string $label,
        string $tone,
        int $progress,
    ): void {
        self::assertSame(
            ['label' => $label, 'tone' => $tone, 'progress' => $progress],
            PublicOrderStatus::describe($status),
        );
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function statuses(): iterable
    {
        yield 'pending' => ['pending', 'En attente de paiement', 'waiting', 1];
        yield 'awaiting payment' => ['on-hold', 'Règlement à confirmer', 'waiting', 1];
        yield 'preparation' => ['processing', 'En préparation', 'active', 2];
        yield 'delivery' => ['out-for-delivery', 'En cours de livraison', 'active', 3];
        yield 'pickup' => ['ready-for-pickup', 'Prête au retrait', 'active', 3];
        yield 'completed' => ['completed', 'Terminée', 'complete', 4];
        yield 'cancelled' => ['cancelled', 'Annulée', 'cancelled', 0];
        yield 'refunded' => ['refunded', 'Remboursée', 'cancelled', 0];
        yield 'failed' => ['failed', 'Échouée', 'cancelled', 0];
        yield 'custom fallback' => ['unknown-status', 'En cours de traitement', 'active', 1];
    }

    public function testProgressStepsAdaptToPickupAndCancellation(): void
    {
        $pickup = PublicOrderStatus::steps('ready-for-pickup', 'pickup');
        self::assertSame(['done', 'done', 'current', 'upcoming'], array_column($pickup, 'state'));
        self::assertSame('Prête au retrait', $pickup[2]['label']);

        $cancelled = PublicOrderStatus::steps('cancelled', 'delivery');
        self::assertSame(['inactive', 'inactive', 'inactive', 'inactive'], array_column($cancelled, 'state'));

        // Une commande terminée montre toutes ses étapes « faites », pas « en cours ».
        $completed = PublicOrderStatus::steps('completed', 'delivery');
        self::assertSame(['done', 'done', 'done', 'done'], array_column($completed, 'state'));
    }
}
