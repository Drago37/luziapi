<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

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
