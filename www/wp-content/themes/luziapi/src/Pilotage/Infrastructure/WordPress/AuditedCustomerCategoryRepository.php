<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategoryRepository;

final readonly class AuditedCustomerCategoryRepository implements CustomerCategoryRepository
{
    public function __construct(
        private CustomerCategoryRepository $inner,
        private ActivityRecorder $activity,
    ) {
    }

    public function forCustomerIds(array $customerIds): array
    {
        return $this->inner->forCustomerIds($customerIds);
    }

    public function assign(
        array $customerIds,
        CustomerCategory $category,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void {
        $assigned = $this->inner->forCustomerIds($customerIds);
        $before = CustomerCategory::Unspecified;
        foreach ($customerIds as $customerId) {
            if (isset($assigned[$customerId])) {
                $before = $assigned[$customerId];
                break;
            }
        }
        $this->inner->assign($customerIds, $category, $actorId, $updatedAt);
        if ($before === $category) {
            return;
        }

        $this->activity->record(
            ActivityCategory::Customer,
            'customer_category_changed',
            'customer_category',
            null,
            'Catégorie d’une fiche client modifiée',
            ['Avant' => $before->label(), 'Après' => $category->label()],
            $actorId,
        );
    }
}
