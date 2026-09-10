<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Query\GetCustomerLoyalty;

/**
 * Demande l'état de fidélité d'un client. `customerKeys` = tous les `identityIds`
 * du profil Pilotage (un même client peut avoir plusieurs e-mails/téléphones) ;
 * le journal est agrégé sur cet ensemble.
 */
final readonly class GetCustomerLoyaltyQuery
{
    /**
     * @param list<string> $customerKeys
     */
    public function __construct(public array $customerKeys)
    {
    }
}
