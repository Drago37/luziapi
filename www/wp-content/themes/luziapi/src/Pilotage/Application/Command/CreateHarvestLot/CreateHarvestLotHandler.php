<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Command\CreateHarvestLot;

use InvalidArgumentException;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Port\StockLevelGateway;
use LuziApi\Pilotage\Domain\Inventory\HarvestLot;
use LuziApi\Pilotage\Domain\Inventory\InventoryRepository;
use LuziApi\Pilotage\Domain\Inventory\NewHarvestLot;
use LuziApi\Pilotage\Domain\Product\ProductCatalog;
use RuntimeException;
use Throwable;

final readonly class CreateHarvestLotHandler
{
    public function __construct(
        private InventoryRepository $inventory,
        private ProductCatalog $products,
        private StockLevelGateway $stock,
        private Clock $clock,
    ) {
    }

    public function handle(CreateHarvestLotCommand $command): HarvestLot
    {
        $lotNumber = trim($command->lotNumber);
        if (1 !== preg_match('/^[A-Za-z0-9._-]{3,64}$/', $lotNumber)) {
            throw new InvalidArgumentException('Invalid lot number.');
        }
        if ('' === trim($command->apiaryOrigin) || '' === trim($command->variety) || $command->quantityJarred <= 0) {
            throw new InvalidArgumentException('Lot information is incomplete.');
        }
        $currentYear = (int) $this->clock->now()->format('Y');
        $harvestYear = (int) $command->harvestedAt->format('Y');
        if ($harvestYear < 2000 || $harvestYear > $currentYear) {
            throw new InvalidArgumentException('Invalid harvest date.');
        }
        // Comparaison au jour près : une mise en pots « aujourd'hui » à n'importe
        // quelle heure reste valable, et une récolte le même jour que la mise en
        // pots passe (la date de récolte est prise à minuit). Éviter une garde à
        // la minute, trop sensible aux imprécisions d'horloge et de saisie.
        $today = $this->clock->now()->format('Y-m-d');
        $jarDay = $command->jarredAt->format('Y-m-d');
        $harvestDay = $command->harvestedAt->format('Y-m-d');
        if ($jarDay > $today) {
            throw new InvalidArgumentException('Jar date cannot be in the future.');
        }
        if ($harvestDay > $jarDay) {
            throw new InvalidArgumentException('Harvest date must be before jar date.');
        }
        $product = null;
        foreach ($this->products->all() as $candidate) {
            if ($candidate->id === $command->productId) {
                $product = $candidate;
                break;
            }
        }
        if (null === $product || null === $product->stockQuantity) {
            throw new RuntimeException('Product must use WooCommerce stock management.');
        }

        if ($command->stockAlreadyRecorded) {
            $trackedStock = 0;
            foreach ($this->inventory->lots() as $existingLot) {
                if ($existingLot->productId === $command->productId) {
                    $trackedStock += $existingLot->stockRemaining;
                }
            }
            $unallocatedStock = max(0, $product->stockQuantity - $trackedStock);
            if ($command->quantityJarred > $unallocatedStock) {
                throw new InvalidArgumentException('Existing stock quantity exceeds unallocated WooCommerce stock.');
            }
        }

        $stockDelta = $command->stockAlreadyRecorded ? 0 : $command->quantityJarred;
        if ($stockDelta > 0) {
            $this->stock->adjust($command->productId, $stockDelta);
        }
        try {
            return $this->inventory->createLotWithInitialStock(new NewHarvestLot(
                $lotNumber,
                $command->productId,
                $command->harvestedAt,
                $command->jarredAt,
                trim($command->apiaryOrigin),
                trim($command->variety),
                $command->quantityJarred,
                $command->stockAlreadyRecorded,
                $command->actorId,
                $this->clock->now(),
            ));
        } catch (Throwable $exception) {
            if ($stockDelta > 0) {
                $this->stock->adjust($command->productId, -$stockDelta);
            }

            throw $exception;
        }
    }
}
