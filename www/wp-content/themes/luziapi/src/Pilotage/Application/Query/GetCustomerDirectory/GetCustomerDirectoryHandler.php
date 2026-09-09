<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetCustomerDirectory;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;

final readonly class GetCustomerDirectoryHandler
{
    public function __construct(
        private OrderRepository $orders,
        private CustomerHistoryProjector $projector,
        private Clock $clock,
    ) {
    }

    public function handle(GetCustomerDirectoryQuery $query): CustomerDirectoryView
    {
        $firstOrder = $this->orders->firstOrderDate();
        $profiles = null === $firstOrder
            ? []
            : $this->projector->project($this->orders->createdBetween(
                $firstOrder->setTime(0, 0),
                $this->clock->now()->modify('+1 day'),
            ));
        $selected = $this->findSelected($profiles, $query->selectedCustomerId);
        $search = $this->normalize($query->search);

        if ('' !== $search) {
            $profiles = array_values(array_filter(
                $profiles,
                fn (CustomerProfile $profile): bool => $this->matches($profile, $search),
            ));
        }

        $totalCustomers = count($profiles);
        $perPage = max(1, min(100, $query->perPage));
        $totalPages = max(1, (int) ceil($totalCustomers / $perPage));
        $currentPage = min(max(1, $query->page), $totalPages);

        return new CustomerDirectoryView(
            array_slice($profiles, ($currentPage - 1) * $perPage, $perPage),
            $totalCustomers,
            $currentPage,
            $totalPages,
            $selected,
        );
    }

    /**
     * @param list<CustomerProfile> $profiles
     */
    private function findSelected(array $profiles, string $selectedId): ?CustomerProfile
    {
        if ('' === $selectedId) {
            return null;
        }

        foreach ($profiles as $profile) {
            if (hash_equals($profile->id, $selectedId)) {
                return $profile;
            }
        }

        return null;
    }

    private function matches(CustomerProfile $profile, string $search): bool
    {
        $haystack = $this->normalize(implode(' ', [
            $profile->name,
            $profile->city,
            implode(' ', $profile->emails),
            implode(' ', $profile->phones),
            implode(' ', $profile->sources),
        ]));
        if (str_contains($haystack, $search)) {
            return true;
        }

        $searchedPhone = NormalizedPhone::fromString($search)?->value();
        if (null === $searchedPhone) {
            return false;
        }

        foreach ($profile->phones as $phone) {
            if (str_contains(NormalizedPhone::fromString($phone)?->value() ?? '', $searchedPhone)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
    }
}
