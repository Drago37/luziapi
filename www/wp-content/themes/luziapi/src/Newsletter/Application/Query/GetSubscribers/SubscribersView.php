<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Query\GetSubscribers;

use LuziApi\Newsletter\Domain\Subscriber;

final readonly class SubscribersView
{
    /**
     * @param bool             $configured  false = répertoire indisponible (pas de clé API)
     * @param int              $total       nombre total d'abonnés (e-mail et/ou SMS)
     * @param int              $emailCount  nombre d'abonnés e-mail
     * @param int              $smsCount    nombre d'abonnés SMS
     * @param list<Subscriber> $subscribers abonnés affichés (après recherche éventuelle)
     */
    public function __construct(
        public bool $configured,
        public int $total,
        public int $emailCount,
        public int $smsCount,
        public array $subscribers,
    ) {
    }
}
