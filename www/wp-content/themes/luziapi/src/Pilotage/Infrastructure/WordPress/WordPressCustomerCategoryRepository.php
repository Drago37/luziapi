<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Customer\CustomerCategory;
use LuziApi\Pilotage\Domain\Customer\CustomerCategoryRepository;
use RuntimeException;
use wpdb;

final readonly class WordPressCustomerCategoryRepository implements CustomerCategoryRepository
{
    public function __construct(
        private wpdb $database,
        private PilotageSchemaManager $schema,
    ) {
    }

    public function forCustomerIds(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter(
            $customerIds,
            static fn (string $customerId): bool => 1 === preg_match('/^[a-f0-9]{20}$/', $customerId),
        )));
        if ([] === $customerIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($customerIds), '%s'));
        $query = $this->database->prepare(
            'SELECT customer_key, category FROM ' . $this->schema->customerCategoriesTableName() . " WHERE customer_key IN ({$placeholders})",
            ...$customerIds,
        );
        $rows = $this->database->get_results($query, ARRAY_A);
        $categories = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $category = CustomerCategory::tryFrom((string) $row['category']);
            if ($category instanceof CustomerCategory) {
                $categories[(string) $row['customer_key']] = $category;
            }
        }

        return $categories;
    }

    public function assign(
        array $customerIds,
        CustomerCategory $category,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void {
        $this->database->query('START TRANSACTION');
        try {
            foreach (array_unique($customerIds) as $customerId) {
                $saved = $this->database->replace($this->schema->customerCategoriesTableName(), [
                    'customer_key' => $customerId,
                    'category' => $category->value,
                    'updated_by' => $actorId,
                    'updated_at' => $updatedAt->format('Y-m-d H:i:s'),
                ], ['%s', '%s', '%d', '%s']);
                if (false === $saved) {
                    throw new RuntimeException('Unable to assign customer category: ' . $this->database->last_error);
                }
            }
            $this->database->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');

            throw $exception;
        }
    }
}
