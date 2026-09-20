<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress;

use LuziApi\Shared\Infrastructure\Wp;
use LuziApi\Shop\Application\Port\TaxSettings;

final class WordPressTaxSettings implements TaxSettings
{
    private const OPTION = 'luziapi_pilotage_activity_start_year';

    public function activityStartYear(int $fallback): int
    {
        $year = Wp::int(get_option(self::OPTION, $fallback));

        return $year >= 2000 ? $year : $fallback;
    }

    public function saveActivityStartYear(int $year): void
    {
        update_option(self::OPTION, $year, false);
    }
}
