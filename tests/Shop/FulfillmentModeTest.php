<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Sales\FulfillmentMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FulfillmentModeTest extends TestCase
{
    /**
     * @param list<string> $methodIds
     */
    #[DataProvider('shippingMethods')]
    public function testModeFromShippingMethodIds(array $methodIds, string $expected): void
    {
        self::assertSame($expected, FulfillmentMode::fromShippingMethodIds($methodIds));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function shippingMethods(): iterable
    {
        yield 'livraison gratuite' => [['free_shipping'], FulfillmentMode::DELIVERY];
        yield 'retrait local' => [['local_pickup'], FulfillmentMode::PICKUP];
        yield 'aucune méthode' => [[], FulfillmentMode::UNKNOWN];
        yield 'méthode inconnue' => [['flat_rate'], FulfillmentMode::UNKNOWN];
        yield 'première correspondance prime' => [['flat_rate', 'free_shipping'], FulfillmentMode::DELIVERY];
        yield 'retrait avant livraison' => [['local_pickup', 'free_shipping'], FulfillmentMode::PICKUP];
    }

    #[DataProvider('statuses')]
    public function testStatusMatches(string $status, string $mode, bool $expected): void
    {
        self::assertSame($expected, FulfillmentMode::statusMatches($status, $mode));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'en livraison + mode livraison' => ['out_for_delivery', FulfillmentMode::DELIVERY, true];
        yield 'en livraison + mode retrait' => ['out_for_delivery', FulfillmentMode::PICKUP, false];
        yield 'en livraison + mode inconnu' => ['out_for_delivery', FulfillmentMode::UNKNOWN, true];
        yield 'prête retrait + mode retrait' => ['ready_for_pickup', FulfillmentMode::PICKUP, true];
        yield 'prête retrait + mode livraison' => ['ready_for_pickup', FulfillmentMode::DELIVERY, false];
        yield 'prête retrait + mode inconnu' => ['ready_for_pickup', FulfillmentMode::UNKNOWN, true];
        yield 'autre statut indifférent au mode' => ['completed', FulfillmentMode::PICKUP, true];
    }
}
