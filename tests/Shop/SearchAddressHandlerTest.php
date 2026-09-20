<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Application\Query\SearchAddress\SearchAddressHandler;
use LuziApi\Shop\Application\Query\SearchAddress\SearchAddressQuery;
use LuziApi\Shop\Domain\Address\AddressLookup;
use LuziApi\Shop\Domain\Address\AddressSuggestion;
use PHPUnit\Framework\TestCase;

final class SearchAddressHandlerTest extends TestCase
{
    public function testItDoesNotQueryBelowThreeCharacters(): void
    {
        $lookup = new RecordingAddressLookup([]);
        $result = (new SearchAddressHandler($lookup))->handle(new SearchAddressQuery('ab'));

        self::assertSame([], $result);
        self::assertSame(0, $lookup->calls, 'Aucun appel réseau ne doit partir sous 3 caractères.');
    }

    public function testItTrimsAndForwardsTheQuery(): void
    {
        $lookup = new RecordingAddressLookup([new AddressSuggestion('8 Boulevard du Port 80000 Amiens', '8 Boulevard du Port', '80000', 'Amiens')]);
        $result = (new SearchAddressHandler($lookup))->handle(new SearchAddressQuery('  8 bd du port  '));

        self::assertCount(1, $result);
        self::assertSame('8 bd du port', $lookup->lastQuery);
        self::assertSame('Amiens', $result[0]->city);
    }

    public function testItClampsTheLimitBetweenOneAndTen(): void
    {
        $lookup = new RecordingAddressLookup([]);
        $handler = new SearchAddressHandler($lookup);

        $handler->handle(new SearchAddressQuery('rue de la paix', 50));
        self::assertSame(15, $lookup->lastLimit);

        $handler->handle(new SearchAddressQuery('rue de la paix', 0));
        self::assertSame(1, $lookup->lastLimit);
    }
}

final class RecordingAddressLookup implements AddressLookup
{
    public int $calls = 0;
    public string $lastQuery = '';
    public int $lastLimit = 0;

    /** @param list<AddressSuggestion> $suggestions */
    public function __construct(private array $suggestions)
    {
    }

    public function search(string $query, int $limit): array
    {
        ++$this->calls;
        $this->lastQuery = $query;
        $this->lastLimit = $limit;

        return $this->suggestions;
    }
}
