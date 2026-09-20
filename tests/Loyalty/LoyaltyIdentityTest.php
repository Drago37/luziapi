<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Shop\Domain\Customer\NormalizedPhone;
use PHPUnit\Framework\TestCase;

/**
 * Verrou d'identité : la clé fidélité DOIT être identique à celle du projecteur
 * du Pilotage (`substr(sha256('email:'|'phone:' . …), 0, 20)`), sinon la fiche
 * client afficherait zéro pot. Deux niveaux de protection :
 * - des valeurs « golden » figées en dur détectent toute dérive de l'algorithme ;
 * - une reproduction de la logique du projecteur détecte une divergence entre
 *   les deux implémentations.
 */
final class LoyaltyIdentityTest extends TestCase
{
    public function testEmailKeyMatchesFrozenGoldenValue(): void
    {
        $identity = LoyaltyIdentity::fromContact('Client@Example.com ', '');

        self::assertNotNull($identity);
        self::assertSame('d1173945094101863f2b', $identity->key);
    }

    public function testPhoneKeyMatchesFrozenGoldenValue(): void
    {
        $identity = LoyaltyIdentity::fromContact('', '06 12 34 56 78');

        self::assertNotNull($identity);
        self::assertSame('32e9754687a92215d39e', $identity->key);
    }

    public function testEmailIsPreferredOverPhone(): void
    {
        $emailOnly = LoyaltyIdentity::fromContact('client@example.com', '');
        $both = LoyaltyIdentity::fromContact('client@example.com', '0612345678');

        self::assertNotNull($emailOnly);
        self::assertNotNull($both);
        self::assertSame($emailOnly->key, $both->key);
    }

    public function testKeyReproducesProjectorAlgorithm(): void
    {
        $email = 'MixedCase@Example.com';
        $phone = '0612345678';

        $identity = LoyaltyIdentity::fromContact($email, $phone);
        $projectorKey = substr(hash('sha256', 'email:' . strtolower(trim($email))), 0, 20);

        self::assertNotNull($identity);
        self::assertSame($projectorKey, $identity->key);
    }

    public function testPhoneKeyReproducesProjectorNormalization(): void
    {
        $phone = '06 12 34 56 78';

        $identity = LoyaltyIdentity::fromContact('', $phone);
        $normalized = NormalizedPhone::fromString($phone)?->value();
        $projectorKey = substr(hash('sha256', 'phone:' . $normalized), 0, 20);

        self::assertNotNull($identity);
        self::assertSame($projectorKey, $identity->key);
    }

    public function testNoContactYieldsNull(): void
    {
        self::assertNull(LoyaltyIdentity::fromContact('', ''));
        self::assertNull(LoyaltyIdentity::fromContact('   ', '123'));
    }

    public function testContactKeysReturnsBothTypedKeys(): void
    {
        $keys = LoyaltyIdentity::contactKeys('Client@Example.com ', '06 12 34 56 78');

        // Mêmes valeurs que les clés priorisées ci-dessus, mais séparées par type.
        self::assertSame('d1173945094101863f2b', $keys['email']);
        self::assertSame('32e9754687a92215d39e', $keys['phone']);
    }

    public function testContactKeysNullsOutMissingSides(): void
    {
        $emailOnly = LoyaltyIdentity::contactKeys('client@example.com', '');
        self::assertSame(LoyaltyIdentity::hash('email:client@example.com'), $emailOnly['email']);
        self::assertNull($emailOnly['phone']);

        $phoneOnly = LoyaltyIdentity::contactKeys('', '0612345678');
        self::assertNull($phoneOnly['email']);
        self::assertNotNull($phoneOnly['phone']);

        $neither = LoyaltyIdentity::contactKeys('  ', 'not-a-phone');
        self::assertNull($neither['email']);
        self::assertNull($neither['phone']);
    }
}
