<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateQuickSale;

use InvalidArgumentException;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Domain\LoyaltyIdentity;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\QuickSaleOrderWriter;
use LuziApi\Pilotage\Domain\Customer\NormalizedPhone;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use Throwable;

final readonly class CreateQuickSaleHandler
{
    public function __construct(
        private QuickSaleOrderWriter $orders,
        private RecordReceiptHandler $recordReceipt,
        private Clock $clock,
        private ?GetCustomerLoyaltyHandler $loyalty = null,
    ) {
    }

    public function handle(CreateQuickSaleCommand $command): CreatedQuickSale
    {
        if ([] === $command->lines && [] === $command->giftLines && [] === $command->rewardLines) {
            throw new InvalidArgumentException('At least one product is required.');
        }
        if (1 !== preg_match('/^[a-f0-9-]{36}$/', $command->requestId)) {
            throw new InvalidArgumentException('Invalid quick sale request identifier.');
        }
        $this->assertValidLines($command->lines);
        $this->assertValidLines($command->giftLines);
        $this->assertValidLines($command->rewardLines);
        $this->assertRewardsAffordable($command);
        if ('' !== $command->email && false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        if ('' !== $command->phone && null === NormalizedPhone::fromString($command->phone)) {
            throw new InvalidArgumentException('Invalid phone number.');
        }
        if ($command->sendEmail && '' === $command->email) {
            throw new InvalidArgumentException('An email address is required to send an email.');
        }
        if ('delivery' === $command->fulfillment && ('' === $command->address || '' === $command->postcode || '' === $command->city)) {
            throw new InvalidArgumentException('Delivery address is required.');
        }
        if (! in_array($command->source, ['online', 'phone', 'market', 'email_form', 'social', 'other'], true)) {
            throw new InvalidArgumentException('Invalid order source.');
        }
        if (! in_array($command->paymentMethod, ['cash', 'cheque', 'bank_transfer', 'wero', 'card', 'other'], true)) {
            throw new InvalidArgumentException('Invalid payment method.');
        }
        if (! in_array($command->fulfillment, ['immediate', 'pickup', 'delivery'], true)) {
            throw new InvalidArgumentException('Invalid fulfillment method.');
        }
        if ($command->occurredAt > $this->clock->now()->modify('+5 minutes')) {
            throw new InvalidArgumentException('Sale date cannot be in the future.');
        }

        $created = $this->orders->create($command);
        if ($created->alreadyExisted) {
            return $created;
        }
        // Rien à encaisser (commande entièrement offerte) : pas de ligne au registre.
        if (! $command->paid || $created->totalCents <= 0) {
            return $created;
        }

        try {
            $this->recordReceipt->handle(new RecordReceiptCommand(
                $created->orderId,
                $command->occurredAt,
                $created->totalCents,
                $command->paymentMethod,
                ReceiptEntryType::Collection,
                'Vente — commande n°' . $created->orderNumber,
                $command->actorId,
            ));
        } catch (Throwable $exception) {
            throw new QuickSaleReceiptFailed($created, $exception);
        }

        $this->orders->markReceiptRecorded($created->orderId);

        return new CreatedQuickSale($created->orderId, $created->orderNumber, $created->totalCents, true, false);
    }

    /**
     * @param list<QuickSaleLine> $lines
     */
    private function assertValidLines(array $lines): void
    {
        $productIds = [];
        foreach ($lines as $line) {
            if ($line->productId <= 0 || $line->quantity <= 0 || isset($productIds[$line->productId])) {
                throw new InvalidArgumentException('Invalid product lines.');
            }
            $productIds[$line->productId] = true;
        }
    }

    /**
     * Un pot offert au titre de la fidélité consomme un avantage : on refuse d'en
     * offrir plus que le client n'en a de disponibles (et zéro sans client
     * identifiable).
     */
    private function assertRewardsAffordable(CreateQuickSaleCommand $command): void
    {
        $requested = array_sum(array_map(static fn (QuickSaleLine $line): int => $line->quantity, $command->rewardLines));
        if ($requested <= 0) {
            return;
        }

        $available = 0;
        if (null !== $this->loyalty) {
            $keys = LoyaltyIdentity::keysForContact($command->email, $command->phone);
            $available = [] === $keys ? 0 : $this->loyalty->availableRewards($keys);
        }
        if ($requested > $available) {
            throw new InvalidArgumentException('Not enough loyalty rewards available for this customer.');
        }
    }
}
