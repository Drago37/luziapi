<?php

declare(strict_types=1);

namespace LuziApi\Tests\OrderTracking;

use DateTimeImmutable;
use InvalidArgumentException;
use LuziApi\OrderTracking\Application\Command\RecordOrderStatusChange\RecordOrderStatusChangeHandler;
use LuziApi\OrderTracking\Application\Command\RedeemHistoryLink\RedeemHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RequestHistoryLink\RequestHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RevokeTrackingSession\RevokeTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Command\StartOrderAccess\StartOrderAccessHandler;
use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\MagicLinkSender;
use LuziApi\OrderTracking\Application\Port\MagicLinkUrlGenerator;
use LuziApi\OrderTracking\Application\Port\OrderTrackingGateway;
use LuziApi\OrderTracking\Application\Port\TokenGenerator;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use LuziApi\OrderTracking\Application\Query\ResolveTrackingSession\ResolveTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Service\TrackingSessionIssuer;
use LuziApi\OrderTracking\Application\View\PublicOrderPage;
use LuziApi\OrderTracking\Domain\OrderAccessCredentials;
use LuziApi\OrderTracking\Domain\StatusHistoryRepository;
use LuziApi\OrderTracking\Domain\StatusTransition;
use PHPUnit\Framework\TestCase;

final class OrderTrackingApplicationTest extends TestCase
{
    private TrackingClock $clock;
    private TrackingAccessRepositoryInMemory $access;
    private TrackingOrdersInMemory $orders;
    private TrackingFingerprint $fingerprints;
    private TrackingTokens $tokens;
    private TrackingSessionIssuer $sessions;

    protected function setUp(): void
    {
        $this->clock = new TrackingClock(new DateTimeImmutable('2026-09-09 12:00:00'));
        $this->access = new TrackingAccessRepositoryInMemory();
        $this->orders = new TrackingOrdersInMemory();
        $this->fingerprints = new TrackingFingerprint();
        $this->tokens = new TrackingTokens();
        $this->sessions = new TrackingSessionIssuer($this->access, $this->tokens, $this->fingerprints);
    }

    public function testDirectAccessCreatesASessionRestrictedToTheVerifiedOrder(): void
    {
        $this->orders->verifiedOrderId = 1042;
        $handler = $this->startHandler();

        $result = $handler->handle(new OrderAccessCredentials('1042', 'client@example.com'), '127.0.0.1');

        self::assertSame('granted', $result->status);
        self::assertSame('1042', $result->orderNumber);
        self::assertNotNull($result->session);
        self::assertSame([1042], $this->access->sessions['token:' . $result->session->token]['ids']);
        self::assertSame('2026-09-09 14:00:00', $result->session->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testWrongOrderAndEmailCombinationNeverCreatesASession(): void
    {
        $result = $this->startHandler()->handle(
            new OrderAccessCredentials('1042', 'other@example.com'),
            '127.0.0.1',
        );

        self::assertSame('denied', $result->status);
        self::assertNull($result->session);
        self::assertSame([], $this->access->sessions);
    }

    public function testDirectAccessIsRateLimitedByCredentials(): void
    {
        $handler = $this->startHandler();
        $credentials = new OrderAccessCredentials('1042', 'other@example.com');

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            self::assertSame('denied', $handler->handle($credentials, '127.0.0.1')->status);
        }

        self::assertSame('limited', $handler->handle($credentials, '127.0.0.1')->status);
        self::assertSame(6, $this->orders->verificationCalls);
    }

    public function testDirectAccessIsAlsoRateLimitedByOrigin(): void
    {
        $handler = $this->startHandler();

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $result = $handler->handle(
                new OrderAccessCredentials((string) (1000 + $attempt), 'client' . $attempt . '@example.com'),
                '127.0.0.1',
            );
            self::assertSame('denied', $result->status);
        }

        $limited = $handler->handle(new OrderAccessCredentials('9999', 'last@example.com'), '127.0.0.1');
        self::assertSame('limited', $limited->status);
        self::assertSame(20, $this->orders->verificationCalls);
    }

    public function testKnownEmailReceivesASingleUseLinkWithoutBeingStoredInTheGrant(): void
    {
        $this->orders->historyIds = [1042, 1043];
        $sender = new TrackingMailSender();
        $handler = $this->historyHandler($sender);

        $result = $handler->handle(' Client@Example.COM ', '127.0.0.1');

        self::assertTrue($result->messageSent);
        self::assertFalse($result->rateLimited);
        self::assertSame('client@example.com', $sender->email);
        $generatedToken = $this->tokens->generated[0];
        self::assertSame('https://example.test/suivi-commande/?acces=' . $generatedToken, $sender->url);
        self::assertSame([1042, 1043], $this->access->grants['token:' . $generatedToken]['ids']);
        self::assertStringNotContainsString('client@example.com', serialize($this->access->grants));
        self::assertSame('2026-09-09 12:15:00', $sender->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testUnknownEmailGetsTheSameAcceptedApplicationOutcomeButNoMessage(): void
    {
        $sender = new TrackingMailSender();

        $result = $this->historyHandler($sender)->handle('unknown@example.com', '127.0.0.1');

        self::assertFalse($result->messageSent);
        self::assertFalse($result->rateLimited);
        self::assertSame('', $sender->email);
        self::assertSame([], $this->access->grants);
    }

    public function testHistoryRequestsAreRateLimitedWithoutRevealingWhetherTheEmailExists(): void
    {
        $this->orders->historyIds = [1042];
        $sender = new TrackingMailSender();
        $handler = $this->historyHandler($sender);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertTrue($handler->handle('client@example.com', '127.0.0.1')->messageSent);
        }
        $limited = $handler->handle('client@example.com', '127.0.0.1');

        self::assertFalse($limited->messageSent);
        self::assertTrue($limited->rateLimited);
        self::assertSame(3, $sender->calls);
    }

    public function testHistoryRequestsAreAlsoRateLimitedByOrigin(): void
    {
        $this->orders->historyIds = [1042];
        $sender = new TrackingMailSender();
        $handler = $this->historyHandler($sender);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            self::assertTrue($handler->handle('client' . $attempt . '@example.com', '127.0.0.1')->messageSent);
        }

        $limited = $handler->handle('last@example.com', '127.0.0.1');
        self::assertTrue($limited->rateLimited);
        self::assertSame(10, $sender->calls);
    }

    public function testHistoryRequestRejectsMalformedEmailBeforeQueryingOrders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->historyHandler(new TrackingMailSender())->handle('wrong', '127.0.0.1');
        } finally {
            self::assertSame(0, $this->orders->historyCalls);
        }
    }

    public function testMagicLinkIsConsumedOnceThenReplacedByASession(): void
    {
        $this->access->issueMagicLink(
            'token:' . $this->validToken('m'),
            [1042, 1043],
            $this->clock->now()->modify('+15 minutes'),
            $this->clock->now(),
        );
        $handler = new RedeemHistoryLinkHandler($this->access, $this->sessions, $this->fingerprints, $this->clock);

        $session = $handler->handle($this->validToken('m'));

        self::assertNotNull($session);
        self::assertSame([1042, 1043], $this->access->sessions['token:' . $session->token]['ids']);
        self::assertNull($handler->handle($this->validToken('m')));
    }

    public function testExpiredOrMalformedMagicLinkIsRejected(): void
    {
        $this->access->issueMagicLink(
            'token:' . $this->validToken('e'),
            [1042],
            $this->clock->now()->modify('-1 second'),
            $this->clock->now()->modify('-20 minutes'),
        );
        $handler = new RedeemHistoryLinkHandler($this->access, $this->sessions, $this->fingerprints, $this->clock);

        self::assertNull($handler->handle('bad'));
        self::assertNull($handler->handle($this->validToken('e')));
        self::assertSame([], $this->access->sessions);
    }

    public function testSessionResolutionOnlyPassesItsAllowedOrderIdsToTheGateway(): void
    {
        $this->access->createSession(
            'token:' . $this->validToken('s'),
            [1042, 1043],
            $this->clock->now()->modify('+2 hours'),
            $this->clock->now(),
        );
        $expected = new PublicOrderPage([], 1, 1, 0, null);
        $this->orders->page = $expected;
        $handler = new ResolveTrackingSessionHandler($this->access, $this->orders, $this->fingerprints, $this->clock);

        $actual = $handler->handle($this->validToken('s'), 2, 10, '1043');

        self::assertSame($expected, $actual);
        self::assertSame([1042, 1043], $this->orders->requestedIds);
        self::assertSame([2, 10, '1043'], $this->orders->requestedPage);
        self::assertNull($handler->handle('invalid', 1, 10, ''));
    }

    public function testSessionExpiresAndCanBeRevokedExplicitly(): void
    {
        $session = $this->sessions->issue([1042], $this->clock->now());
        $resolver = new ResolveTrackingSessionHandler($this->access, $this->orders, $this->fingerprints, $this->clock);
        self::assertNotNull($resolver->handle($session->token, 1, 10, ''));

        (new RevokeTrackingSessionHandler($this->access, $this->fingerprints))->handle($session->token);
        self::assertNull($resolver->handle($session->token, 1, 10, ''));

        $expired = $this->sessions->issue([1043], $this->clock->now());
        $this->clock->current = $expired->expiresAt->modify('+1 second');
        self::assertNull($resolver->handle($expired->token, 1, 10, ''));
    }

    public function testStatusChangesAreRecordedButNoOpTransitionsAreIgnored(): void
    {
        $history = new TrackingStatusHistoryInMemory();
        $handler = new RecordOrderStatusChangeHandler($history, $this->clock);

        $handler->handle(1042, 'processing', 'ready-for-pickup');
        $handler->handle(1042, 'processing', 'processing');
        $handler->handle(0, '', 'processing');

        self::assertCount(1, $history->entries);
        self::assertSame('ready-for-pickup', $history->entries[0]->toStatus);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $history->entries[0]->referenceKey);
    }

    private function startHandler(): StartOrderAccessHandler
    {
        return new StartOrderAccessHandler(
            $this->orders,
            $this->access,
            $this->sessions,
            $this->fingerprints,
            $this->clock,
        );
    }

    private function historyHandler(TrackingMailSender $sender): RequestHistoryLinkHandler
    {
        return new RequestHistoryLinkHandler(
            $this->orders,
            $this->access,
            $this->tokens,
            $this->fingerprints,
            new TrackingUrls(),
            $sender,
            $this->clock,
        );
    }

    private function validToken(string $character): string
    {
        return str_repeat($character, 43);
    }
}

final class TrackingClock implements Clock
{
    public function __construct(public DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

final class TrackingFingerprint implements AccessFingerprint
{
    public function token(string $rawToken): string
    {
        return 'token:' . $rawToken;
    }

    public function subject(string $value): string
    {
        return 'subject:' . mb_strtolower(trim($value));
    }
}

final class TrackingTokens implements TokenGenerator
{
    private int $sequence = 0;
    /** @var list<string> */
    public array $generated = [];

    public function generate(): string
    {
        ++$this->sequence;
        $token = substr(hash('sha256', 'tracking-token-' . $this->sequence), 0, 43);
        $this->generated[] = $token;

        return $token;
    }
}

final class TrackingAccessRepositoryInMemory implements TrackingAccessRepository
{
    /** @var array<string, array{count: int, started: DateTimeImmutable}> */
    private array $attempts = [];

    /** @var array<string, array{ids: non-empty-list<int>, expires: DateTimeImmutable, consumed: bool}> */
    public array $grants = [];

    /** @var array<string, array{ids: non-empty-list<int>, expires: DateTimeImmutable}> */
    public array $sessions = [];

    public function allowAttempt(
        string $scope,
        string $subjectFingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $at,
    ): bool {
        $key = $scope . '|' . $subjectFingerprint;
        $attempt = $this->attempts[$key] ?? null;
        if (null === $attempt || $attempt['started']->modify('+' . $windowSeconds . ' seconds') <= $at) {
            $this->attempts[$key] = ['count' => 1, 'started' => $at];

            return true;
        }
        if ($attempt['count'] >= $limit) {
            return false;
        }
        ++$this->attempts[$key]['count'];

        return true;
    }

    public function issueMagicLink(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->grants[$tokenFingerprint] = ['ids' => $orderIds, 'expires' => $expiresAt, 'consumed' => false];
    }

    public function consumeMagicLink(string $tokenFingerprint, DateTimeImmutable $at): ?array
    {
        $grant = $this->grants[$tokenFingerprint] ?? null;
        if (null === $grant || $grant['consumed'] || $grant['expires'] < $at) {
            return null;
        }
        $this->grants[$tokenFingerprint]['consumed'] = true;

        return $grant['ids'];
    }

    public function createSession(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->sessions[$tokenFingerprint] = ['ids' => $orderIds, 'expires' => $expiresAt];
    }

    public function sessionOrderIds(string $tokenFingerprint, DateTimeImmutable $at): ?array
    {
        $session = $this->sessions[$tokenFingerprint] ?? null;

        return null !== $session && $session['expires'] >= $at ? $session['ids'] : null;
    }

    public function revokeSession(string $tokenFingerprint): void
    {
        unset($this->sessions[$tokenFingerprint]);
    }

    public function purgeExpired(DateTimeImmutable $at): void
    {
    }
}

final class TrackingOrdersInMemory implements OrderTrackingGateway
{
    public ?int $verifiedOrderId = null;
    /** @var list<int> */
    public array $historyIds = [];
    public int $verificationCalls = 0;
    public int $historyCalls = 0;
    /** @var list<int> */
    public array $requestedIds = [];
    /** @var array{int, int, string} */
    public array $requestedPage = [0, 0, ''];
    public ?PublicOrderPage $page = null;

    public function findOrderId(string $orderNumber, string $email): ?int
    {
        ++$this->verificationCalls;

        return $this->verifiedOrderId;
    }

    public function findOrderIdsByEmail(string $email): array
    {
        ++$this->historyCalls;

        return $this->historyIds;
    }

    public function getOrders(array $allowedOrderIds, int $page, int $perPage, string $selectedOrderNumber): PublicOrderPage
    {
        $this->requestedIds = $allowedOrderIds;
        $this->requestedPage = [$page, $perPage, $selectedOrderNumber];

        return $this->page ?? new PublicOrderPage([], 1, 1, 0, null);
    }
}

final class TrackingUrls implements MagicLinkUrlGenerator
{
    public function forToken(string $token): string
    {
        return 'https://example.test/suivi-commande/?acces=' . $token;
    }
}

final class TrackingMailSender implements MagicLinkSender
{
    public string $email = '';
    public string $url = '';
    public ?DateTimeImmutable $expiresAt = null;
    public int $calls = 0;

    public function send(string $email, string $accessUrl, DateTimeImmutable $expiresAt): bool
    {
        $this->email = $email;
        $this->url = $accessUrl;
        $this->expiresAt = $expiresAt;
        ++$this->calls;

        return true;
    }
}

final class TrackingStatusHistoryInMemory implements StatusHistoryRepository
{
    /** @var list<StatusTransition> */
    public array $entries = [];

    public function record(StatusTransition $transition): void
    {
        $this->entries[] = $transition;
    }

    public function forOrderIds(array $orderIds): array
    {
        return [];
    }
}
