<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Domain\LoyaltyEntry;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\NewLoyaltyEntry;

/**
 * Journal de fidélité en mémoire pour les tests. Reproduit fidèlement le contrat
 * du port : idempotence sur `idempotencyKey`, agrégation par clé client.
 */
final class InMemoryLoyaltyLedger implements LoyaltyLedger
{
    /** @var list<LoyaltyEntry> */
    public array $entries = [];

    private int $nextId = 1;

    public function append(NewLoyaltyEntry $entry): ?int
    {
        if ($this->hasEntryForIdempotencyKey($entry->idempotencyKey)) {
            return null;
        }

        $id = $this->nextId++;
        $this->entries[] = new LoyaltyEntry(
            $id,
            $entry->customerKey,
            $entry->type,
            $entry->potsDelta,
            $entry->rightsDelta,
            $entry->sourceOrderId,
            $entry->usageOrderId,
            $entry->reversalOfId,
            $entry->idempotencyKey,
            $entry->reason,
            $entry->createdBy,
            $entry->occurredAt,
            $entry->createdAt,
        );

        return $id;
    }

    public function hasEntryForIdempotencyKey(string $idempotencyKey): bool
    {
        return null !== $this->findByIdempotencyKey($idempotencyKey);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?LoyaltyEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->idempotencyKey === $idempotencyKey) {
                return $entry;
            }
        }

        return null;
    }

    public function totalsForCustomerKeys(array $customerKeys): array
    {
        $pots = 0;
        $rights = 0;
        $count = 0;
        foreach ($this->forKeys($customerKeys) as $entry) {
            $pots += $entry->potsDelta;
            $rights += $entry->rightsDelta;
            ++$count;
        }

        return ['pots' => $pots, 'rightsConsumed' => max(0, -$rights), 'entryCount' => $count];
    }

    public function balancesByCustomerKeys(array $customerKeys): array
    {
        $balances = [];
        foreach ($this->forKeys($customerKeys) as $entry) {
            $balances[$entry->customerKey] ??= ['pots' => 0, 'rights' => 0];
            $balances[$entry->customerKey]['pots'] += $entry->potsDelta;
            $balances[$entry->customerKey]['rights'] += $entry->rightsDelta;
        }

        return $balances;
    }

    public function entriesForCustomerKeys(array $customerKeys, int $limit = 50): array
    {
        $entries = $this->forKeys($customerKeys);
        usort(
            $entries,
            static fn (LoyaltyEntry $a, LoyaltyEntry $b): int => [$b->occurredAt, $b->id] <=> [$a->occurredAt, $a->id],
        );

        return array_slice($entries, 0, $limit);
    }

    /**
     * @param list<string> $customerKeys
     *
     * @return list<LoyaltyEntry>
     */
    private function forKeys(array $customerKeys): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (LoyaltyEntry $entry): bool => in_array($entry->customerKey, $customerKeys, true),
        ));
    }
}
