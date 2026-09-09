<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class CustomerHistoryProjectorTest extends TestCase
{
    public function testItJoinsAPhoneOnlyOrderToOneUnambiguousEmailHistory(): void
    {
        $profiles = (new CustomerHistoryProjector())->project([
            $this->order(1, '2026-01-10', 'camille@example.test', '06 12 34 56 78'),
            $this->order(2, '2026-03-15', '', '+33 6 12 34 56 78'),
        ]);

        self::assertCount(1, $profiles);
        self::assertSame(['camille@example.test'], $profiles[0]->emails);
        self::assertSame(2, $profiles[0]->validOrdersCount);
        self::assertSame(4_000, $profiles[0]->orderedTotal->cents());
        self::assertSame('2', $profiles[0]->lastOrder()->number);
    }

    public function testItDoesNotMergePhoneOnlyHistoryWhenSeveralEmailsShareThePhone(): void
    {
        $profiles = (new CustomerHistoryProjector())->project([
            $this->order(1, '2026-01-10', 'camille@example.test', '06 12 34 56 78'),
            $this->order(2, '2026-02-10', 'alex@example.test', '06 12 34 56 78'),
            $this->order(3, '2026-03-10', '', '06 12 34 56 78'),
        ]);

        self::assertCount(3, $profiles);
    }

    public function testItIgnoresAnonymousOrdersWithoutContactDetails(): void
    {
        $profiles = (new CustomerHistoryProjector())->project([
            $this->order(1, '2026-01-10', '', ''),
        ]);

        self::assertSame([], $profiles);
    }

    private function order(int $id, string $date, string $email, string $phone): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable($date),
            'completed',
            new Money(2_000),
            Money::zero(),
            2,
            'Camille Martin',
            $email,
            $phone,
            'Luzillé',
            'phone',
            'pickup',
        );
    }
}
