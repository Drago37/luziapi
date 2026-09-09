<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Query\GetCustomerDirectory;

use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Domain\Customer\CustomerCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategoryRepository;
use LuziApi\Pilotage\Domain\Customer\CustomerHistoryProjector;
use LuziApi\Pilotage\Domain\Customer\CustomerProfile;
use LuziApi\Pilotage\Domain\Customer\CustomerTimelineRepository;
use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use LuziApi\Pilotage\Domain\Sales\OrderRepository;

final readonly class GetCustomerDirectoryHandler
{
    public function __construct(
        private OrderRepository $orders,
        private CustomerHistoryProjector $projector,
        private CustomerCategoryRepository $categories,
        private ReceiptRepository $receipts,
        private CustomerTimelineRepository $timeline,
        private Clock $clock,
    ) {
    }

    public function handle(GetCustomerDirectoryQuery $query): CustomerDirectoryView
    {
        $firstOrder = $this->orders->firstOrderDate();
        $orders = null === $firstOrder ? [] : $this->orders->createdBetween(
            $firstOrder->setTime(0, 0),
            $this->clock->now()->modify('+1 day'),
        );
        $profiles = $this->projector->project(
            $orders,
            $this->receipts->netTotalsByOrderIds(array_map(static fn ($order): int => $order->id, $orders)),
        );
        $identityIds = [];
        foreach ($profiles as $profile) {
            array_push($identityIds, ...$profile->identityIds);
        }
        $assignedCategories = $this->categories->forCustomerIds($identityIds);
        $profiles = array_map(
            static fn (CustomerProfile $profile): CustomerProfile => $profile->withCategory(
                self::categoryFor($profile, $assignedCategories),
            ),
            $profiles,
        );
        $selected = $this->findSelected($profiles, $query->selectedCustomerId);
        $search = $this->normalize($query->search);

        if ('' !== $search) {
            $profiles = array_values(array_filter(
                $profiles,
                fn (CustomerProfile $profile): bool => $this->matches($profile, $search),
            ));
        }
        if ($query->category instanceof CustomerCategory) {
            $profiles = array_values(array_filter(
                $profiles,
                static fn (CustomerProfile $profile): bool => $profile->category === $query->category,
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
            null === $selected ? [] : $this->timeline->forOrderIds(array_map(static fn ($order): int => $order->id, $selected->orders)),
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
            $profile->category->label(),
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

    /** @param array<string, CustomerCategory> $assignedCategories */
    private static function categoryFor(CustomerProfile $profile, array $assignedCategories): CustomerCategory
    {
        foreach ($profile->identityIds as $identityId) {
            if (isset($assignedCategories[$identityId])) {
                return $assignedCategories[$identityId];
            }
        }

        return CustomerCategory::Unspecified;
    }
}
