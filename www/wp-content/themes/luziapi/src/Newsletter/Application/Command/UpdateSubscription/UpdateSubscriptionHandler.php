<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Command\UpdateSubscription;

use InvalidArgumentException;
use LuziApi\Newsletter\Domain\Gateway\SubscriberWriter;
use LuziApi\Newsletter\Domain\SubscriptionStatus;

final readonly class UpdateSubscriptionHandler
{
    public function __construct(private SubscriberWriter $writer)
    {
    }

    /**
     * @return SubscriptionStatus état confirmé par relecture côté Brevo
     */
    public function handle(UpdateSubscriptionCommand $command): SubscriptionStatus
    {
        $email = trim($command->email);
        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Un e-mail valide est requis pour gérer l’abonnement.');
        }
        if ($command->smsSubscribed && '' === trim($command->phone)) {
            throw new InvalidArgumentException('Un numéro de mobile est requis pour l’abonnement SMS.');
        }

        return $this->writer->setSubscription(
            $email,
            trim($command->phone),
            $command->emailSubscribed,
            $command->smsSubscribed,
            trim($command->firstName),
            trim($command->lastName),
        );
    }
}
