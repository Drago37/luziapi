<?php

declare(strict_types=1);

namespace LuziApi\Shared\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendrier des jours ouvrés français (jours fériés inclus), utilisé pour les
 * échéances métier. Purement calculatoire : aucune dépendance à WordPress.
 */
final class BusinessCalendar
{
    /**
     * Jours fériés français d'une année (fixes + mobiles calés sur Pâques),
     * au format Y-m-d.
     *
     * @return list<string>
     */
    public static function publicHolidays(int $year): array
    {
        $timezone = new DateTimeZone('Europe/Paris');
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            $timezone
        );

        return [
            sprintf('%d-01-01', $year),
            sprintf('%d-05-01', $year),
            sprintf('%d-05-08', $year),
            sprintf('%d-07-14', $year),
            sprintf('%d-08-15', $year),
            sprintf('%d-11-01', $year),
            sprintf('%d-11-11', $year),
            sprintf('%d-12-25', $year),
            $easter->modify('+1 day')->format('Y-m-d'),
            $easter->modify('+39 days')->format('Y-m-d'),
            $easter->modify('+50 days')->format('Y-m-d'),
        ];
    }

    public static function isBusinessDay(DateTimeImmutable $date): bool
    {
        $weekday = (int) $date->format('N');
        if ($weekday > 5) {
            return false;
        }

        return ! in_array(
            $date->format('Y-m-d'),
            self::publicHolidays((int) $date->format('Y')),
            true
        );
    }

    public static function addBusinessDays(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        $cursor = $start;
        $added  = 0;

        while ($added < max(0, $days)) {
            $cursor = $cursor->modify('+1 day');
            if (self::isBusinessDay($cursor)) {
                ++$added;
            }
        }

        return $cursor;
    }
}
