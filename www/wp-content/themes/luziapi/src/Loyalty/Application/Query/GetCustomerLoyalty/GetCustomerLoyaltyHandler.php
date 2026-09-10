<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Query\GetCustomerLoyalty;

use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\LoyaltyProgress;

final readonly class GetCustomerLoyaltyHandler
{
    public function __construct(private LoyaltyLedger $ledger)
    {
    }

    public function handle(GetCustomerLoyaltyQuery $query): CustomerLoyaltyView
    {
        $keys = array_values(array_unique(array_filter(
            $query->customerKeys,
            static fn (string $key): bool => '' !== $key,
        )));
        if ([] === $keys) {
            return CustomerLoyaltyView::empty();
        }

        $totals = $this->ledger->totalsForCustomerKeys($keys);
        $progress = new LoyaltyProgress($totals['pots'], $totals['rightsConsumed']);
        $entries = $this->ledger->entriesForCustomerKeys($keys);

        return CustomerLoyaltyView::fromProgress($progress, $entries);
    }

    /**
     * Avantages disponibles pour un ensemble de clés (tous les `identityIds` d'un
     * profil). Utilisé par la Vente pour proposer / borner les pots offerts.
     *
     * @param list<string> $customerKeys
     */
    public function availableRewards(array $customerKeys): int
    {
        $totals = $this->ledger->totalsForCustomerKeys($customerKeys);

        return (new LoyaltyProgress($totals['pots'], $totals['rightsConsumed']))->rightsAvailable;
    }

    /**
     * Avantages disponibles de plusieurs clients d'un coup (liste de la Vente), en
     * une seule requête. La clé de sortie est l'identifiant de client fourni.
     *
     * @param array<string, list<string>> $keysByCustomer identifiant client => ses `identityIds`
     *
     * @return array<string, int>
     */
    public function availableRewardsByCustomer(array $keysByCustomer): array
    {
        $allKeys = [];
        foreach ($keysByCustomer as $keys) {
            foreach ($keys as $key) {
                if ('' !== $key) {
                    $allKeys[$key] = true;
                }
            }
        }
        if ([] === $allKeys) {
            return [];
        }

        $balances = $this->ledger->balancesByCustomerKeys(array_keys($allKeys));

        $available = [];
        foreach ($keysByCustomer as $customerId => $keys) {
            $pots = 0;
            $rights = 0;
            foreach ($keys as $key) {
                $pots += $balances[$key]['pots'] ?? 0;
                $rights += $balances[$key]['rights'] ?? 0;
            }
            $available[$customerId] = (new LoyaltyProgress($pots, max(0, -$rights)))->rightsAvailable;
        }

        return $available;
    }
}
