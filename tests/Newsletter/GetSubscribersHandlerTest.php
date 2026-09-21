<?php

declare(strict_types=1);

namespace LuziApi\Tests\Newsletter;

use LuziApi\Newsletter\Domain\Gateway\SubscriberDirectory;
use LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersHandler;
use LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersQuery;
use LuziApi\Newsletter\Domain\Subscriber;
use LuziApi\Newsletter\Domain\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class GetSubscribersHandlerTest extends TestCase
{
    public function testItReportsUnavailableWhenTheDirectoryIsNotConfigured(): void
    {
        $view = (new GetSubscribersHandler(new FakeSubscriberDirectory(false, [])))
            ->handle(new GetSubscribersQuery());

        self::assertFalse($view->configured);
        self::assertSame(0, $view->total);
        self::assertSame(0, $view->emailCount);
        self::assertSame(0, $view->smsCount);
        self::assertSame(0, $view->blockedCount);
        self::assertSame([], $view->subscribers);
    }

    public function testItCountsActiveSmsOnlyAndBlockedSubscribers(): void
    {
        $view = (new GetSubscribersHandler(new FakeSubscriberDirectory(true, [
            new Subscriber('a@example.test', '+33600000001'),                       // e-mail + SMS actifs
            new Subscriber('b@example.test'),                                       // e-mail seul
            new Subscriber('c@example.test', '+33600000003', true, true),           // e-mail ET SMS bloqués
            new Subscriber('', '+33600000004'),                                     // SMS uniquement (sans e-mail)
        ])))->handle(new GetSubscribersQuery());

        self::assertTrue($view->configured);
        self::assertSame(4, $view->total);
        self::assertSame(2, $view->emailCount);   // a, b (c est bloqué e-mail)
        self::assertSame(2, $view->smsCount);     // a, et le SMS-seul (c est bloqué SMS)
        self::assertSame(1, $view->blockedCount); // c
        self::assertCount(4, $view->subscribers);
    }

    public function testItFiltersBySearchOnEmailOrPhone(): void
    {
        $directory = new FakeSubscriberDirectory(true, [
            new Subscriber('alice@example.test', '+33611111111'),
            new Subscriber('bob@example.test'),
        ]);

        $byEmail = (new GetSubscribersHandler($directory))->handle(new GetSubscribersQuery('ALICE'));
        self::assertCount(1, $byEmail->subscribers);
        self::assertSame('alice@example.test', $byEmail->subscribers[0]->email);

        // La recherche filtre l'affichage mais pas les compteurs globaux.
        self::assertSame(2, $byEmail->emailCount);

        $byPhone = (new GetSubscribersHandler($directory))->handle(new GetSubscribersQuery('61111'));
        self::assertCount(1, $byPhone->subscribers);
        self::assertSame('alice@example.test', $byPhone->subscribers[0]->email);
    }
}

final class FakeSubscriberDirectory implements SubscriberDirectory
{
    /** @param list<Subscriber> $subscribers */
    public function __construct(
        private bool $configured,
        private array $subscribers,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function all(): array
    {
        return $this->subscribers;
    }

    public function statusFor(?string $email, ?string $phone): ?SubscriptionStatus
    {
        return null;
    }
}
