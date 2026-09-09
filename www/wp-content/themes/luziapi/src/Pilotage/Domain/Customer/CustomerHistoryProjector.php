<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Domain\Customer;

use LuziApi\Pilotage\Domain\Sales\OrderSnapshot;
use LuziApi\Pilotage\Domain\Sales\OrderStatusPolicy;
use LuziApi\Pilotage\Domain\Shared\Money;

final class CustomerHistoryProjector
{
    /**
     * @param list<OrderSnapshot> $orders
     *
     * @return list<CustomerProfile>
     */
    public function project(array $orders, array $receiptTotalsByOrder = []): array
    {
        $prepared = [];
        $phoneEmails = [];

        foreach ($orders as $order) {
            $email = strtolower(trim($order->email));
            $phone = NormalizedPhone::fromString($order->phone)?->value() ?? '';
            if ('' === $email && '' === $phone) {
                continue;
            }

            $prepared[] = [$order, $email, $phone];
            if ('' !== $email && '' !== $phone) {
                $phoneEmails[$phone][$email] = true;
            }
        }

        $groups = [];
        foreach ($prepared as [$order, $email, $phone]) {
            $key = $this->groupKey($email, $phone, $phoneEmails);
            $groups[$key][] = $order;
        }

        $profiles = [];
        foreach ($groups as $key => $customerOrders) {
            usort(
                $customerOrders,
                static fn (OrderSnapshot $left, OrderSnapshot $right): int => $right->createdAt <=> $left->createdAt,
            );
            $profiles[] = $this->profile($key, $customerOrders, $receiptTotalsByOrder);
        }

        usort(
            $profiles,
            static fn (CustomerProfile $left, CustomerProfile $right): int => $right->lastOrder()->createdAt <=> $left->lastOrder()->createdAt,
        );

        return $profiles;
    }

    /**
     * @param array<string, array<string, true>> $phoneEmails
     */
    private function groupKey(string $email, string $phone, array $phoneEmails): string
    {
        if ('' !== $email) {
            return 'email:' . $email;
        }

        if (1 === count($phoneEmails[$phone] ?? [])) {
            return 'email:' . (string) array_key_first($phoneEmails[$phone]);
        }

        return 'phone:' . $phone;
    }

    /**
     * @param non-empty-list<OrderSnapshot> $orders
     */
    private function profile(string $key, array $orders, array $receiptTotalsByOrder): CustomerProfile
    {
        $latest = $orders[0];
        $emails = [];
        $phones = [];
        $sources = [];
        $validOrdersCount = 0;
        $orderedTotal = Money::zero();
        $collectedTotal = Money::zero();
        $products = [];

        foreach ($orders as $order) {
            $email = strtolower(trim($order->email));
            if ('' !== $email) {
                $emails[$email] = $email;
            }
            $normalizedPhone = NormalizedPhone::fromString($order->phone)?->value();
            if ($normalizedPhone) {
                $phones[$normalizedPhone] = $order->phone;
            }
            if ('' !== $order->source) {
                $sources[$order->source] = $order->source;
            }
            if (OrderStatusPolicy::isCommerciallyValid($order->status)) {
                ++$validOrdersCount;
                $orderedTotal = $orderedTotal->add($order->total)->subtract($order->refunded);
            }
            $collectedTotal = $collectedTotal->add(new Money($receiptTotalsByOrder[$order->id] ?? 0));
            foreach ($order->lines as $line) {
                $products[$line->name] = ($products[$line->name] ?? 0) + $line->quantity;
            }
        }
        arsort($products);

        return new CustomerProfile(
            substr(hash('sha256', $key), 0, 20),
            $latest->customerName,
            $latest->city,
            array_values($emails),
            array_values($phones),
            array_values($sources),
            $orders,
            $validOrdersCount,
            $orderedTotal,
            $collectedTotal,
            array_slice(array_keys($products), 0, 3),
        );
    }
}
