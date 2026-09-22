<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\Shop\Domain\Customer\CustomerBilling;
use LuziApi\Shop\Domain\Customer\CustomerProfileRepository;
use RuntimeException;
use wpdb;

final readonly class WordPressCustomerProfileRepository implements CustomerProfileRepository
{
    public function __construct(
        private wpdb $database,
        private ShopSchemaManager $schema,
    ) {
    }

    public function forCustomerIds(array $customerIds): array
    {
        $wanted = array_flip(array_filter(
            $customerIds,
            static fn (string $customerId): bool => 1 === preg_match('/^[a-f0-9]{20}$/', $customerId),
        ));
        if ([] === $wanted) {
            return [];
        }

        // La table des fiches ne contient que les clients édités à la main (quelques
        // lignes) : on la lit en entier et on filtre en PHP. Aucune donnée externe
        // n'entre dans la requête (le nom de table vient du schéma), donc pas de
        // `prepare` ni d'IN() dynamique à composer.
        $rows = $this->database->get_results(
            'SELECT customer_key, first_name, last_name, company, address_1, address_2, postcode, city, country, email, phone FROM '
            . $this->schema->customerProfilesTableName(),
            ARRAY_A,
        );
        $str = static fn (mixed $value): string => is_string($value) ? $value : '';
        $profiles = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $str($row['customer_key'] ?? null);
            if ('' === $key || ! isset($wanted[$key])) {
                continue;
            }
            $profiles[$key] = new CustomerBilling(
                $str($row['first_name'] ?? null),
                $str($row['last_name'] ?? null),
                $str($row['company'] ?? null),
                $str($row['address_1'] ?? null),
                $str($row['address_2'] ?? null),
                $str($row['postcode'] ?? null),
                $str($row['city'] ?? null),
                $str($row['country'] ?? null),
                $str($row['email'] ?? null),
                $str($row['phone'] ?? null),
            );
        }

        return $profiles;
    }

    public function save(
        array $customerIds,
        CustomerBilling $billing,
        int $actorId,
        DateTimeImmutable $updatedAt,
    ): void {
        $this->database->query('START TRANSACTION');
        try {
            foreach (array_unique($customerIds) as $customerId) {
                $saved = $this->database->replace($this->schema->customerProfilesTableName(), [
                    'customer_key' => $customerId,
                    'first_name'   => $billing->firstName,
                    'last_name'    => $billing->lastName,
                    'company'      => $billing->company,
                    'address_1'    => $billing->address1,
                    'address_2'    => $billing->address2,
                    'postcode'     => $billing->postcode,
                    'city'         => $billing->city,
                    'country'      => $billing->country,
                    'email'        => $billing->email,
                    'phone'        => $billing->phone,
                    'updated_by'   => $actorId,
                    'updated_at'   => $updatedAt->format('Y-m-d H:i:s'),
                ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
                if (false === $saved) {
                    throw new RuntimeException('Unable to save customer profile: ' . $this->database->last_error);
                }
            }
            $this->database->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');

            throw $exception;
        }
    }
}
