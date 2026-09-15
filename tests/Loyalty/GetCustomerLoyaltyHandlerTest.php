<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Domain\LoyaltyEntryType;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/FixedClock.php';

final class GetCustomerLoyaltyHandlerTest extends TestCase
{
    private InMemoryLoyaltyLedger $ledger;
    private RecordCompletedOrderHandler $record;
    private GetCustomerLoyaltyHandler $query;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLoyaltyLedger();
        $this->record = new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00'));
        $this->query = new GetCustomerLoyaltyHandler($this->ledger);
    }

    public function testPotsOlderThanTwoYearsExpireWhenAClockIsProvided(): void
    {
        // Un crédit d'il y a 3 ans et un crédit récent, même client.
        (new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2023-01-01 10:00:00')))
            ->handle(new RecordCompletedOrderCommand(1, 'key', 6));
        (new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00')))
            ->handle(new RecordCompletedOrderCommand(2, 'key', 4));

        // Sans horloge : tous les pots comptent (10).
        $allTime = (new GetCustomerLoyaltyHandler($this->ledger))
            ->handle(new GetCustomerLoyaltyQuery(['key']));
        self::assertSame(10, $allTime->netPots);

        // Avec horloge à 2026 : le crédit de 2023 (> 2 ans) est expiré, reste 4.
        $withExpiry = (new GetCustomerLoyaltyHandler($this->ledger, FixedClock::at('2026-09-10 10:00:00')))
            ->handle(new GetCustomerLoyaltyQuery(['key']));
        self::assertSame(4, $withExpiry->netPots);
    }

    public function testEmptyWhenNoKeys(): void
    {
        $view = $this->query->handle(new GetCustomerLoyaltyQuery([]));

        self::assertSame(0, $view->netPots);
        self::assertSame(0, $view->rewardsAcquired);
        self::assertSame([], $view->entries);
    }

    public function testAggregatesAcrossAllIdentityKeysOfTheProfile(): void
    {
        // Même client, deux commandes rattachées à deux clés d'identité distinctes
        // (ex. une par e-mail, une par téléphone) — la fiche agrège les deux.
        $this->record->handle(new RecordCompletedOrderCommand(1, 'key-email', 10));
        $this->record->handle(new RecordCompletedOrderCommand(2, 'key-phone', 6));

        $view = $this->query->handle(new GetCustomerLoyaltyQuery(['key-email', 'key-phone']));

        self::assertSame(16, $view->netPots);
        self::assertSame(1, $view->rewardsAcquired);
        self::assertSame(1, $view->potsTowardNextReward);
        self::assertCount(2, $view->entries);
    }

    public function testIgnoresOtherCustomersEntries(): void
    {
        $this->record->handle(new RecordCompletedOrderCommand(1, 'key-mine', 4));
        $this->record->handle(new RecordCompletedOrderCommand(2, 'key-other', 9));

        $view = $this->query->handle(new GetCustomerLoyaltyQuery(['key-mine']));

        self::assertSame(4, $view->netPots);
        self::assertCount(1, $view->entries);
    }

    /**
     * Les deux chemins qui donnent le nombre d'avantages disponibles — `availableRewards()`
     * (un client, appelé par la fiche) et `availableRewardsByCustomer()` (plusieurs clients
     * d'un coup, appelé par la liste de la Vente) — doivent renvoyer exactement la même
     * valeur pour un même client. Un écart afficherait « 1 avantage » côté fiche et « 0 »
     * côté Vente (ou l'inverse). Le scénario cumule ce qui peut les faire diverger :
     * plusieurs clés d'identité, un avantage déjà consommé, et un crédit expiré (> 2 ans).
     */
    public function testAvailableRewardsIsConsistentAcrossBothReadPaths(): void
    {
        $clock = FixedClock::at('2026-09-10 10:00:00');

        // Un même client, deux clés d'identité (e-mail + téléphone) : 30 pots récents.
        (new RecordCompletedOrderHandler($this->ledger, $clock))
            ->handle(new RecordCompletedOrderCommand(1, 'key-email', 18));
        (new RecordCompletedOrderHandler($this->ledger, $clock))
            ->handle(new RecordCompletedOrderCommand(2, 'key-phone', 12));
        // Un crédit d'il y a 3 ans : expiré, il ne doit compter dans aucun des deux chemins.
        (new RecordCompletedOrderHandler($this->ledger, FixedClock::at('2023-01-01 10:00:00')))
            ->handle(new RecordCompletedOrderCommand(3, 'key-email', 30));
        // Un avantage déjà consommé (les droits ne s'expirent jamais).
        $this->ledger->append(new NewLoyaltyEntry(
            'key-email',
            LoyaltyEntryType::RewardConsumed,
            0,
            -1,
            null,
            4,
            null,
            'reward-consumed-4',
            'Pot offert fidélité',
            0,
            new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('Europe/Paris')),
        ));

        $keys = ['key-email', 'key-phone'];
        $handler = new GetCustomerLoyaltyHandler($this->ledger, $clock);

        // 30 pots récents → 2 avantages acquis ; 1 consommé → 1 disponible.
        $single = $handler->availableRewards($keys);
        $batch = $handler->availableRewardsByCustomer(['the-customer' => $keys])['the-customer'];

        self::assertSame(1, $single);
        self::assertSame($single, $batch);
    }
}
