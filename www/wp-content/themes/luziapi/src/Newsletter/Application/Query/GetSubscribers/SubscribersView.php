<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Application\Query\GetSubscribers;

use LuziApi\Newsletter\Domain\Subscriber;

final readonly class SubscribersView
{
    /**
     * @param bool             $configured   false = répertoire indisponible (pas de clé API)
     * @param int              $total        nombre total de contacts (e-mail et/ou SMS)
     * @param int              $emailCount   abonnés e-mail actifs (non bloqués)
     * @param int              $smsCount     abonnés SMS actifs (non bloqués)
     * @param int              $blockedCount contacts ayant bloqué au moins un canal
     * @param list<Subscriber> $subscribers  contacts affichés (après recherche éventuelle)
     */
    public function __construct(
        public bool $configured,
        public int $total,
        public int $emailCount,
        public int $smsCount,
        public int $blockedCount,
        public array $subscribers,
    ) {
    }
}
