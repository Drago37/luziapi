<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\FollowUp\FollowUpProjector;
use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class FollowUpProjectorTest extends TestCase
{
    public function testItUsesReceiptsAndStatusesToBuildActionGroups(): void
    {
        $orders = [
            $this->order(1, 'on-hold', 2_000, 'phone', 'pickup'),
            $this->order(2, 'processing', 3_000, 'online', 'delivery'),
            $this->order(3, 'ready-for-pickup', 1_000, '', 'pickup'),
        ];

        $board = (new FollowUpProjector())->project($orders, [1 => 500], new DateTimeImmutable('2026-09-09'));

        self::assertCount(1, $board->payments);
        self::assertSame(1_500, $board->payments[0]->outstanding->cents());
        self::assertCount(1, $board->preparation);
        self::assertCount(1, $board->handover);
        self::assertCount(1, $board->inconsistencies);
        self::assertSame(4, $board->totalActions());
    }

    private function order(int $id, string $status, int $total, string $source, string $fulfillment): OrderSnapshot
    {
        return new OrderSnapshot(
            $id,
            (string) $id,
            new DateTimeImmutable('2026-09-01'),
            $status,
            new Money($total),
            Money::zero(),
            1,
            'Client test',
            '',
            '0600000000',
            'Luzillé',
            $source,
            $fulfillment,
        );
    }
}
