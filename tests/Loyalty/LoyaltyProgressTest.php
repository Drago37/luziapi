<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Domain\LoyaltyProgress;
use PHPUnit\Framework\TestCase;

final class LoyaltyProgressTest extends TestCase
{
    public function testNoPotsMeansNoRewardAndFullTrancheAhead(): void
    {
        $progress = new LoyaltyProgress(0);

        self::assertSame(0, $progress->netPots);
        self::assertSame(0, $progress->rightsAcquired);
        self::assertSame(0, $progress->rightsAvailable);
        self::assertSame(0, $progress->potsTowardNextReward);
        self::assertSame(15, $progress->potsUntilNextReward());
    }

    public function testFifteenPotsAcquireExactlyOneReward(): void
    {
        $progress = new LoyaltyProgress(15);

        self::assertSame(1, $progress->rightsAcquired);
        self::assertSame(1, $progress->rightsAvailable);
        self::assertSame(0, $progress->potsTowardNextReward);
        self::assertSame(15, $progress->potsUntilNextReward());
    }

    public function testThirtyThreePotsGiveTwoRewardsAndThreeInProgress(): void
    {
        $progress = new LoyaltyProgress(33);

        self::assertSame(33, $progress->netPots);
        self::assertSame(2, $progress->rightsAcquired);
        self::assertSame(3, $progress->potsTowardNextReward);
        self::assertSame(12, $progress->potsUntilNextReward());
    }

    public function testConsumedRewardsLowerAvailabilityButNotAcquisition(): void
    {
        $progress = new LoyaltyProgress(33, rightsConsumed: 1);

        self::assertSame(2, $progress->rightsAcquired);
        self::assertSame(1, $progress->rightsAvailable);
    }

    public function testNegativeNetIsClampedToZero(): void
    {
        $progress = new LoyaltyProgress(-5);

        self::assertSame(0, $progress->netPots);
        self::assertSame(0, $progress->rightsAcquired);
    }
}
