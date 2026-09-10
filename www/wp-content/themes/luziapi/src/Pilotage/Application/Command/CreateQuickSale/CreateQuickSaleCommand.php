<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

use DateTimeImmutable;
use LuziApi\Pilotage\Domain\Sales\ThankYouDiscount;

final readonly class CreateQuickSaleCommand
{
    /**
     * @param list<QuickSaleLine> $lines       lignes payées
     * @param list<QuickSaleLine> $giftLines   lignes offertes (geste commercial, 0 €, hors fidélité)
     * @param list<QuickSaleLine> $rewardLines lignes offertes au titre de la fidélité (0 €, consomment un avantage)
     */
    public function __construct(
        public array $lines,
        public string $customerName,
        public string $email,
        public string $phone,
        public string $address,
        public string $postcode,
        public string $city,
        public string $source,
        public string $paymentMethod,
        public string $fulfillment,
        public bool $paid,
        public bool $sendEmail,
        public DateTimeImmutable $occurredAt,
        public int $actorId,
        public string $requestId,
        public array $giftLines = [],
        public array $rewardLines = [],
        public ?ThankYouDiscount $discount = null,
    ) {
    }
}
