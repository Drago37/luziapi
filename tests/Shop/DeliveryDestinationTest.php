<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Sales\DeliveryDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryDestinationTest extends TestCase
{
    /**
     * La ville est reçue déjà normalisée (minuscules, sans accent, lettres
     * uniquement) : la normalisation WordPress est faite par l'adaptateur et
     * couverte par l'e2e de zone de livraison.
     */
    #[DataProvider('destinations')]
    public function testQualifiesForFreeDelivery(string $country, string $postcode, string $normalizedCity, bool $expected): void
    {
        $destination = new DeliveryDestination($country, $postcode, $normalizedCity);

        self::assertSame($expected, $destination->qualifiesForFreeDelivery());
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function destinations(): iterable
    {
        yield 'Bléré éligible' => ['FR', '37150', 'blere', true];
        yield 'Luzillé éligible' => ['FR', '37150', 'luzille', true];
        yield 'pays en minuscules' => ['fr', '37150', 'blere', true];
        yield 'code postal avec espace' => ['FR', '37 150', 'blere', true];
        yield 'même code postal, autre commune' => ['FR', '37150', 'atheesurcher', false];
        yield 'autre code postal' => ['FR', '37000', 'tours', false];
        yield 'hors France' => ['BE', '37150', 'blere', false];
        yield 'pays avec espace parasite (non toléré, comme l’existant)' => [' FR', '37150', 'blere', false];
        yield 'ville vide' => ['FR', '37150', '', false];
    }
}
