<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Sales\DeliveryDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryDestinationTest extends TestCase
{
    #[DataProvider('destinations')]
    public function testQualifiesForFreeDelivery(string $country, string $postcode, string $city, bool $expected): void
    {
        $destination = new DeliveryDestination($country, $postcode, $city);

        self::assertSame($expected, $destination->qualifiesForFreeDelivery());
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function destinations(): iterable
    {
        yield 'Bléré éligible' => ['FR', '37150', 'Bléré', true];
        yield 'Luzillé éligible' => ['FR', '37150', 'Luzillé', true];
        yield 'casse ignorée' => ['FR', '37150', 'BLÉRÉ', true];
        yield 'accents et espaces ignorés' => ['FR', '37150', '  luzille ', true];
        yield 'pays en minuscules' => ['fr', '37150', 'Bléré', true];
        yield 'code postal avec espace' => ['FR', '37 150', 'Bléré', true];
        yield 'même code postal, autre commune' => ['FR', '37150', 'Athée-sur-Cher', false];
        yield 'autre code postal' => ['FR', '37000', 'Tours', false];
        yield 'hors France' => ['BE', '37150', 'Bléré', false];
        yield 'ville vide' => ['FR', '37150', '', false];
    }

    #[DataProvider('cities')]
    public function testNormalizeCity(string $raw, string $expected): void
    {
        self::assertSame($expected, DeliveryDestination::normalizeCity($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cities(): iterable
    {
        yield 'accents repliés' => ['Bléré', 'blere'];
        yield 'majuscules accentuées' => ['LUZILLÉ', 'luzille'];
        yield 'espaces et tiret retirés' => [' Saint-Martin ', 'saintmartin'];
        yield 'chiffres retirés' => ['Tours37', 'tours'];
        yield 'chaîne vide' => ['', ''];
    }
}
