<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\Application\Port;

interface TaxSettings
{
    public function activityStartYear(int $fallback): int;

    public function saveActivityStartYear(int $year): void;
}
