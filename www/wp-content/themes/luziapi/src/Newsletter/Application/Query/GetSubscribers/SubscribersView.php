<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Query\GetSubscribers;

use LuziApi\Newsletter\Domain\Subscriber;

final readonly class SubscribersView
{
    /**
     * @param bool             $configured  false = répertoire indisponible (pas de clé API)
     * @param int              $emailCount  nombre d'abonnés e-mail (toute la liste)
     * @param int              $smsCount    nombre d'abonnés SMS (sous-ensemble)
     * @param list<Subscriber> $subscribers abonnés affichés (après recherche éventuelle)
     */
    public function __construct(
        public bool $configured,
        public int $emailCount,
        public int $smsCount,
        public array $subscribers,
    ) {
    }
}
