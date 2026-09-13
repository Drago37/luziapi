<?php

declare(strict_types=1);

namespace LuziApi\Tests\Newsletter;

use LuziApi\Newsletter\Domain\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

final class SubscriptionStatusTest extends TestCase
{
    public function testNoneIsNotSubscribed(): void
    {
        $status = SubscriptionStatus::none();

        self::assertFalse($status->emailSubscribed);
        self::assertFalse($status->smsSubscribed);
        self::assertFalse($status->isSubscribed());
    }

    public function testSubscribedWhenEitherChannelIsOn(): void
    {
        self::assertTrue((new SubscriptionStatus(true, false))->isSubscribed());
        self::assertTrue((new SubscriptionStatus(false, true))->isSubscribed());
        self::assertTrue((new SubscriptionStatus(true, true))->isSubscribed());
    }
}
