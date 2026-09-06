<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderWorkflowTest extends TestCase
{
    /**
     * @param array<string, string> $destination
     */
    #[DataProvider('destinationProvider')]
    public function testLocalDeliveryRestriction(array $destination, bool $expected): void
    {
        self::assertSame($expected, luziapi_is_local_delivery_destination($destination));
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function destinationProvider(): array
    {
        return [
            'Bléré' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Bléré'],
                true,
            ],
            'Blere sans accent' => [
                ['country' => 'fr', 'postcode' => '37150', 'city' => 'BLERE'],
                true,
            ],
            'Luzillé' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Luzillé'],
                true,
            ],
            'Luzille avec espaces' => [
                ['country' => 'FR', 'postcode' => '37 150', 'city' => '  luzille  '],
                true,
            ],
            'Autre commune du 37150' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => 'Dierre'],
                false,
            ],
            'Bléré avec mauvais code postal' => [
                ['country' => 'FR', 'postcode' => '37000', 'city' => 'Bléré'],
                false,
            ],
            'Bléré hors de France' => [
                ['country' => 'BE', 'postcode' => '37150', 'city' => 'Bléré'],
                false,
            ],
            'Adresse incomplète' => [
                ['country' => 'FR', 'postcode' => '37150', 'city' => ''],
                false,
            ],
        ];
    }
}
