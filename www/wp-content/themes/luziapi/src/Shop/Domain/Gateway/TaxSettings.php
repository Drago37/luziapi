<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Gateway;

interface TaxSettings
{
    public function activityStartYear(int $fallback): int;

    public function saveActivityStartYear(int $year): void;
}
