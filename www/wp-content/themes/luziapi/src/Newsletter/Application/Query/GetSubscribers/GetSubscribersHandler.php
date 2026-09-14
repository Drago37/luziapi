<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Query\GetSubscribers;

use LuziApi\Newsletter\Application\Port\SubscriberDirectory;
use LuziApi\Newsletter\Domain\Subscriber;

final readonly class GetSubscribersHandler
{
    public function __construct(private SubscriberDirectory $directory)
    {
    }

    public function handle(GetSubscribersQuery $query): SubscribersView
    {
        if (! $this->directory->isConfigured()) {
            return new SubscribersView(false, 0, 0, 0, 0, []);
        }

        $all = $this->directory->all();
        $total = count($all);
        $emailCount = count(array_filter($all, static fn (Subscriber $s): bool => $s->emailSubscribed()));
        $smsCount = count(array_filter($all, static fn (Subscriber $s): bool => $s->smsSubscribed()));
        $blockedCount = count(array_filter($all, static fn (Subscriber $s): bool => $s->hasBlockedChannel()));

        $search = trim(mb_strtolower($query->search));
        $rows = '' === $search
            ? $all
            : array_values(array_filter($all, static function (Subscriber $s) use ($search): bool {
                return str_contains(mb_strtolower($s->email), $search)
                    || (null !== $s->phone && str_contains($s->phone, $search));
            }));

        return new SubscribersView(true, $total, $emailCount, $smsCount, $blockedCount, $rows);
    }
}
