<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use LuziApi\Pilotage\Domain\Customer\CustomerBilling;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class CustomerProfileTest extends TestCase
{
    public function testPrimaryContactUsesTheFirstKnownEmailAndPhone(): void
    {
        $profile = $this->profile(['a@example.com', 'b@example.com'], ['0600000001', '0600000002']);

        self::assertSame('a@example.com', $profile->primaryEmail());
        self::assertSame('0600000001', $profile->primaryPhone());
    }

    public function testPrimaryContactIsEmptyWhenTheChannelIsUnknown(): void
    {
        $profile = $this->profile([], []);

        self::assertSame('', $profile->primaryEmail());
        self::assertSame('', $profile->primaryPhone());
    }

    public function testOverrideChangesDisplayButKeepsIdentityAndFallsBackOnEmptyFields(): void
    {
        $profile = $this->profile(['a@example.com'], ['0600000001'])
            ->withOverride(new CustomerBilling('Hélène', 'Dupont', '', '3 rue des Abeilles', '', '37150', '', 'FR', 'helene@example.test', ''));

        // Nom et e-mail reflètent la fiche ; la ville vide retombe sur la projection ;
        // le téléphone vide conserve celui des commandes.
        self::assertSame('Hélène Dupont', $profile->name);
        self::assertSame('helene@example.test', $profile->emails[0]);
        self::assertSame('Luzillé', $profile->city);
        self::assertSame('0600000001', $profile->phones[0]);
        // L'identité (donc catégorie/fidélité) est inchangée.
        self::assertSame(['identity-1'], $profile->identityIds);
        self::assertSame('37150', $profile->billingOverride?->postcode);
    }

    /**
     * @param list<string> $emails
     * @param list<string> $phones
     */
    private function profile(array $emails, array $phones): CustomerProfile
    {
        return new CustomerProfile(
            'id-1',
            'Camille Test',
            'Luzillé',
            $emails,
            $phones,
            ['market'],
            [],
            0,
            Money::zero(),
            Money::zero(),
            [],
            ['identity-1'],
        );
    }
}
