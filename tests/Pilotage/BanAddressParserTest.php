<?php

declare(strict_types=1);

namespace LuziApi\Tests\Pilotage;

use LuziApi\Pilotage\Infrastructure\Http\BanAddressParser;
use PHPUnit\Framework\TestCase;

final class BanAddressParserTest extends TestCase
{
    public function testItParsesTheBanGeoJsonFeatures(): void
    {
        $json = (string) json_encode([
            'features' => [
                ['properties' => ['label' => '8 Boulevard du Port 80000 Amiens', 'name' => '8 Boulevard du Port', 'postcode' => '80000', 'city' => 'Amiens']],
                ['properties' => ['label' => '3 Rue des Abeilles 37150 Luzillé', 'name' => '3 Rue des Abeilles', 'postcode' => '37150', 'city' => 'Luzillé']],
            ],
        ]);

        $suggestions = BanAddressParser::fromResponseBody($json);

        self::assertCount(2, $suggestions);
        self::assertSame('8 Boulevard du Port', $suggestions[0]->street);
        self::assertSame('80000', $suggestions[0]->postcode);
        self::assertSame('Amiens', $suggestions[0]->city);
        self::assertSame('37150', $suggestions[1]->postcode);
        self::assertSame('Luzillé', $suggestions[1]->city);
    }

    public function testItFallsBackToTheLabelWhenNameIsMissing(): void
    {
        $json = (string) json_encode([
            'features' => [
                ['properties' => ['label' => 'Luzillé 37150', 'postcode' => '37150', 'city' => 'Luzillé']],
            ],
        ]);

        $suggestions = BanAddressParser::fromResponseBody($json);

        self::assertCount(1, $suggestions);
        self::assertSame('Luzillé 37150', $suggestions[0]->street);
    }

    public function testItSkipsFeaturesWithoutALabel(): void
    {
        $json = (string) json_encode([
            'features' => [
                ['properties' => ['name' => 'Voie sans label', 'postcode' => '37000']],
                'not-an-array',
                ['no_properties' => true],
            ],
        ]);

        self::assertSame([], BanAddressParser::fromResponseBody($json));
    }

    public function testItReturnsEmptyOnMalformedOrEmptyPayloads(): void
    {
        self::assertSame([], BanAddressParser::fromResponseBody('not json'));
        self::assertSame([], BanAddressParser::fromResponseBody('{}'));
        self::assertSame([], BanAddressParser::fromResponseBody('{"features": "nope"}'));
        self::assertSame([], BanAddressParser::fromResponseBody('[]'));
    }
}
