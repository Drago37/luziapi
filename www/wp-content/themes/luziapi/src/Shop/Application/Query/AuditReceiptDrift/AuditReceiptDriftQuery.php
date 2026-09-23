<?php

declare(strict_types=1);

namespace LuziApi\Shop\Application\Query\AuditReceiptDrift;

final readonly class AuditReceiptDriftQuery
{
    /**
     * @param int|null $year année civile à auditer ; null = tout l'historique
     *                       (de la première commande à maintenant)
     */
    public function __construct(
        public ?int $year = null,
    ) {
    }
}
