<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\SearchAddress;

use LuziApi\Pilotage\Domain\Address\AddressLookup;

final readonly class SearchAddressHandler
{
    /** En dessous de 3 caractères, la BAN ne renvoie rien d'utile : on n'appelle pas. */
    private const MIN_LENGTH = 3;

    public function __construct(private AddressLookup $lookup)
    {
    }

    /**
     * @return list<\LuziApi\Pilotage\Domain\Address\AddressSuggestion>
     */
    public function handle(SearchAddressQuery $query): array
    {
        $needle = trim($query->query);
        if (mb_strlen($needle) < self::MIN_LENGTH) {
            return [];
        }
        $limit = max(1, min(15, $query->limit));

        return $this->lookup->search($needle, $limit);
    }
}
