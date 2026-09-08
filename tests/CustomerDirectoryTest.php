<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerDirectoryTest extends TestCase
{
    #[DataProvider('phoneProvider')]
    public function testPhoneNormalization(string $phone, string $expected): void
    {
        self::assertSame($expected, luziapi_normalize_customer_phone($phone));
    }

    /** @return array<string, array{string, string}> */
    public static function phoneProvider(): array
    {
        return [
            'format national'        => ['06 12 34 56 78', '33612345678'],
            'format international'   => ['+33 6 12 34 56 78', '33612345678'],
            'préfixe international'  => ['0033 6 12 34 56 78', '33612345678'],
            'zéro entre parenthèses' => ['+33 (0)6 12 34 56 78', '33612345678'],
            'trop court'             => ['12 34', ''],
        ];
    }

    public function testPhoneOnlyOrdersAreGroupedTogether(): void
    {
        $customers = luziapi_group_customer_records([
            $this->record(2, '', '06 12 34 56 78', 200, 12.0),
            $this->record(1, '', '+33 6 12 34 56 78', 100, 10.0),
        ]);

        self::assertCount(1, $customers);
        self::assertSame(2, $customers[0]['orders_count']);
        self::assertSame(22.0, $customers[0]['total']);
        self::assertSame('phone:33612345678', $customers[0]['key']);
    }

    public function testPhoneOnlyHistoryJoinsTheSingleKnownEmail(): void
    {
        $customers = luziapi_group_customer_records([
            $this->record(2, 'client@example.test', '06 12 34 56 78', 200, 12.0),
            $this->record(1, '', '+33 6 12 34 56 78', 100, 10.0),
        ]);

        self::assertCount(1, $customers);
        self::assertSame('email:client@example.test', $customers[0]['key']);
        self::assertSame(['client@example.test'], $customers[0]['emails']);
        self::assertSame(2, $customers[0]['orders_count']);
    }

    public function testSharedPhoneDoesNotMergeDifferentEmails(): void
    {
        $customers = luziapi_group_customer_records([
            $this->record(3, 'a@example.test', '06 12 34 56 78', 300, 12.0),
            $this->record(2, 'b@example.test', '06 12 34 56 78', 200, 11.0),
            $this->record(1, '', '06 12 34 56 78', 100, 10.0),
        ]);

        self::assertCount(3, $customers);
        self::assertSame(
            ['email:a@example.test', 'email:b@example.test', 'phone:33612345678'],
            array_column($customers, 'key')
        );
    }

    public function testOrdersWithoutEmailOrUsablePhoneAreIgnored(): void
    {
        self::assertSame([], luziapi_group_customer_records([
            $this->record(1, '', '1234', 100, 10.0),
        ]));
    }

    /** @return array<string, mixed> */
    private function record(
        int $id,
        string $email,
        string $phone,
        int $timestamp,
        float $total
    ): array {
        return [
            'order_id'    => $id,
            'order_number' => (string) $id,
            'first_name'  => 'Jean',
            'last_name'   => 'Test',
            'email'       => $email,
            'phone'       => $phone,
            'city'        => 'Luzillé',
            'source'      => 'Téléphone',
            'timestamp'   => $timestamp,
            'total'       => $total,
        ];
    }
}
