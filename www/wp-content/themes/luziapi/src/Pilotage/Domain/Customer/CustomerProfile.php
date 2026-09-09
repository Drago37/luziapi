<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Shared\Money;

final readonly class CustomerProfile
{
    /**
     * @param list<string>        $emails
     * @param list<string>        $phones
     * @param list<string>        $sources
     * @param list<OrderSnapshot> $orders
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $city,
        public array $emails,
        public array $phones,
        public array $sources,
        public array $orders,
        public int $validOrdersCount,
        public Money $orderedTotal,
        public Money $collectedTotal,
        /** @var list<string> */
        public array $favoriteProducts,
        /** @var non-empty-list<string> */
        public array $identityIds,
        public CustomerCategory $category = CustomerCategory::Unspecified,
    ) {
    }

    public function withCategory(CustomerCategory $category): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->city,
            $this->emails,
            $this->phones,
            $this->sources,
            $this->orders,
            $this->validOrdersCount,
            $this->orderedTotal,
            $this->collectedTotal,
            $this->favoriteProducts,
            $this->identityIds,
            $category,
        );
    }

    public function lastOrder(): OrderSnapshot
    {
        return $this->orders[0];
    }
}
