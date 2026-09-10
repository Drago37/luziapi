<?php

declare(strict_types=1);

namespace LuziApi\Tests\OrderTracking;

use DateTimeImmutable;
use LuziApi\OrderTracking\Application\Command\RecordOrderStatusChange\RecordOrderStatusChangeHandler;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Domain\StatusHistoryRepository;
use LuziApi\OrderTracking\Domain\StatusTransition;
use LuziApi\OrderTracking\Infrastructure\WooCommerce\WooCommerceStatusHistorySubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class StatusHistorySubscriberTest extends TestCase
{
    public function testRecordsTheTransitionWhenStorageWorks(): void
    {
        $history = new RecordingStatusHistory();
        $logger = new SpyLogger();
        $subscriber = new WooCommerceStatusHistorySubscriber(
            new RecordOrderStatusChangeHandler($history, new FixedClock()),
            $logger,
        );

        $subscriber->record(1042, 'processing', 'completed');

        self::assertCount(1, $history->transitions);
        self::assertSame('completed', $history->transitions[0]->toStatus);
        self::assertSame([], $logger->records, 'aucune erreur ne doit être journalisée en cas de succès');
    }

    public function testAStorageFailureIsSwallowedAndLoggedWithoutBreakingTheWorkflow(): void
    {
        $logger = new SpyLogger();
        $subscriber = new WooCommerceStatusHistorySubscriber(
            new RecordOrderStatusChangeHandler(new ThrowingStatusHistory(), new FixedClock()),
            $logger,
        );

        // Ne doit RIEN laisser remonter : sinon WooCommerce serait perturbé lors
        // du changement de statut (stock, e-mails, recettes partagent ce hook).
        $subscriber->record(1042, 'processing', 'completed');

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(1042, $logger->records[0]['context']['order_id'] ?? null);
    }
}

final class FixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-09 10:00:00');
    }
}

final class RecordingStatusHistory implements StatusHistoryRepository
{
    /** @var list<StatusTransition> */
    public array $transitions = [];

    public function record(StatusTransition $transition): void
    {
        $this->transitions[] = $transition;
    }

    public function forOrderIds(array $orderIds): array
    {
        return [];
    }
}

final class ThrowingStatusHistory implements StatusHistoryRepository
{
    public function record(StatusTransition $transition): void
    {
        throw new RuntimeException('Base indisponible.');
    }

    public function forOrderIds(array $orderIds): array
    {
        return [];
    }
}

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
