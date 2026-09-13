<?php

declare(strict_types=1);

namespace LuziApi\Tests\Newsletter;

use LuziApi\Newsletter\Application\Port\SubscriberDirectory;
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
        self::assertSame(0, $view->emailCount);
        self::assertSame(0, $view->smsCount);
        self::assertSame([], $view->subscribers);
    }

    public function testItCountsEmailAndSmsSubscribers(): void
    {
        $view = (new GetSubscribersHandler(new FakeSubscriberDirectory(true, [
            new Subscriber('a@example.test', true, '+33600000001'),
            new Subscriber('b@example.test', false),
            new Subscriber('c@example.test', true, '+33600000003'),
        ])))->handle(new GetSubscribersQuery());

        self::assertTrue($view->configured);
        self::assertSame(3, $view->emailCount);
        self::assertSame(2, $view->smsCount);
        self::assertCount(3, $view->subscribers);
    }

    public function testItFiltersBySearchOnEmailOrPhone(): void
    {
        $directory = new FakeSubscriberDirectory(true, [
            new Subscriber('alice@example.test', true, '+33611111111'),
            new Subscriber('bob@example.test', false),
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
