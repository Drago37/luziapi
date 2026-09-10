<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Application\Port;

interface IdGenerator
{
    /** Identifiant opaque unique (clé d'idempotence d'une action ponctuelle). */
    public function newId(): string;
}
