<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Sales\OrderSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderSourceTest extends TestCase
{
    #[DataProvider('sources')]
    public function testResolve(string $storedSource, string $createdVia, string $expected): void
    {
        self::assertSame($expected, OrderSource::resolve($storedSource, $createdVia));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sources(): iterable
    {
        yield 'source explicite prime' => ['phone', 'admin', 'phone'];
        yield 'checkout reconnu en ligne' => ['', 'checkout', 'online'];
        yield 'store-api reconnu en ligne' => ['', 'store-api', 'online'];
        yield 'commande admin sans source' => ['', 'admin', ''];
        yield 'espaces autour de la source' => ['  phone  ', 'admin', 'phone'];
        yield 'source inconnue ignorée, repli checkout' => ['bogus', 'checkout', 'online'];
        yield 'source inconnue, admin, aucune source' => ['bogus', 'admin', ''];
    }

    public function testOptionsCoverTheKnownChannels(): void
    {
        self::assertSame(
            ['online', 'phone', 'market', 'email_form', 'social', 'other'],
            array_keys(OrderSource::options()),
        );
    }
}
