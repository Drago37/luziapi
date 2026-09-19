<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesCommand;
use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesHandler;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderCommand;
use LuziApi\Loyalty\Application\Command\RecordCompletedOrder\RecordCompletedOrderHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryLoyaltyLedger.php';
require_once __DIR__ . '/InMemoryLoyaltyIdentityLinks.php';
require_once __DIR__ . '/FixedClock.php';

final class LoyaltyIdentityLinksTest extends TestCase
{
    public function testExpandReturnsTheKeyItselfWhenUnlinked(): void
    {
        self::assertSame(['a'], (new InMemoryLoyaltyIdentityLinks())->expand(['a']));
    }

    public function testUnionLinksKeysInBothDirections(): void
    {
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['a', 'b']);

        self::assertEqualsCanonicalizing(['a', 'b'], $links->expand(['a']));
        self::assertEqualsCanonicalizing(['a', 'b'], $links->expand(['b']));
    }

    public function testUnionIsTransitive(): void
    {
        // a↔b (e-mail↔téléphone d'origine) puis b↔c (nouveau téléphone) : les trois
        // clés du même client se rejoignent.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['a', 'b']);
        $links->union(['b', 'c']);

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $links->expand(['a']));
    }

    public function testMergingTwoExistingGroups(): void
    {
        // Fusion manuelle de deux clients (a,b) et (c,d) qui ne partageaient rien.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['a', 'b']);
        $links->union(['c', 'd']);
        $links->union(['a', 'c']);

        self::assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $links->expand(['d']));
    }

    public function testUnionOfASingleKeyIsANoop(): void
    {
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['a']);

        self::assertSame(['a'], $links->expand(['a']));
        self::assertSame([], $links->links);
    }

    public function testHandlerAggregatesPotsAcrossLinkedKeys(): void
    {
        $ledger = new InMemoryLoyaltyLedger();
        $record = new RecordCompletedOrderHandler($ledger, FixedClock::at('2026-09-10 10:00:00'));
        // Même personne, deux clés qui ne partagent aucun contact commun.
        $record->handle(new RecordCompletedOrderCommand(1, 'key-email', 10));
        $record->handle(new RecordCompletedOrderCommand(2, 'key-phone', 8));

        // Sans liens : la fiche vue par une seule clé ne voit que ses pots.
        $view = (new GetCustomerLoyaltyHandler($ledger))
            ->handle(new GetCustomerLoyaltyQuery(['key-email']));
        self::assertSame(10, $view->netPots);

        // Avec liens (les deux clés fusionnées) : le solde agrège tout.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['key-email', 'key-phone']);
        $linked = (new GetCustomerLoyaltyHandler($ledger, $links))
            ->handle(new GetCustomerLoyaltyQuery(['key-email']));
        self::assertSame(18, $linked->netPots);
    }

    public function testAutoLinkAttachesSuccessivePhonesToTheSameEmail(): void
    {
        // Changement de numéro : deux téléphones libres rejoignent le même e-mail.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->autoLink('email-a', 'phone-1');
        $links->autoLink('email-a', 'phone-2');

        self::assertEqualsCanonicalizing(['email-a', 'phone-1', 'phone-2'], $links->expand(['email-a']));
    }

    public function testAutoLinkRefusesToAbsorbASecondEmailViaASharedPhone(): void
    {
        // Téléphone déjà rattaché à A : un 2ᵉ e-mail B (foyer / partagé) n'est PAS
        // auto-fusionné — ce cas ambigu relève de la fusion manuelle.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->autoLink('email-a', 'phone-x');
        $links->autoLink('email-b', 'phone-x');

        self::assertSame(['email-b'], $links->expand(['email-b']));
        self::assertEqualsCanonicalizing(['email-a', 'phone-x'], $links->expand(['email-a']));
    }

    public function testUnlinkDetachesKeysIntoTheirOwnGroup(): void
    {
        // Défusion : on sort {c,d} d'un groupe fusionné à tort ; {a,b} reste groupé.
        $links = new InMemoryLoyaltyIdentityLinks();
        $links->union(['a', 'b', 'c', 'd']);
        $links->unlink(['c', 'd']);

        self::assertEqualsCanonicalizing(['a', 'b'], $links->expand(['a']));
        self::assertEqualsCanonicalizing(['c', 'd'], $links->expand(['c']));
    }

    public function testMergeHandlerUnionsBothCustomersKeys(): void
    {
        $links = new InMemoryLoyaltyIdentityLinks();
        (new MergeLoyaltyIdentitiesHandler($links))
            ->handle(new MergeLoyaltyIdentitiesCommand(['a', 'b'], ['c']));

        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $links->expand(['c']));
    }
}
