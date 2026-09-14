<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Command\UpdateSubscription;

final readonly class UpdateSubscriptionCommand
{
    public function __construct(
        public string $email,
        public string $phone,
        public bool $emailSubscribed,
        public bool $smsSubscribed,
    ) {
    }
}
