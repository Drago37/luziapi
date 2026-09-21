<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Query\GetCustomerLoyalty;

use LuziApi\Loyalty\Domain\Gateway\LoyaltyIdentityLinks;
use LuziApi\Loyalty\Domain\LoyaltyLedger;
use LuziApi\Loyalty\Domain\LoyaltyProgress;

final readonly class GetCustomerLoyaltyHandler
{
    /**
     * @param LoyaltyIdentityLinks|null $links étend les clés d'un client à toutes celles
     *                                         de son groupe (2ᵉ e-mail, changement de
     *                                         numéro, fusion manuelle) ; `null` = pas de
     *                                         regroupement. N'est appliqué qu'aux lectures
     *                                         mono-client (fiche, suivi, bornage du geste),
     *                                         pas à la somme multi-clients (pour ne pas
     *                                         double-compter deux profils fusionnés).
     */
    public function __construct(
        private LoyaltyLedger $ledger,
        private ?LoyaltyIdentityLinks $links = null,
    ) {
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
        $keys = $this->expand($keys);

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
        $totals = $this->ledger->totalsForCustomerKeys($this->expand($customerKeys));

        return (new LoyaltyProgress($totals['pots'], $totals['rightsConsumed']))->rightsAvailable;
    }

    /**
     * Étend un ensemble de clés à tout le groupe d'identité du client, si un service
     * de liens est branché. Sinon renvoie les clés telles quelles (dédoublonnées).
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function expand(array $keys): array
    {
        return $this->links?->expand($keys) ?? array_values(array_unique($keys));
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
