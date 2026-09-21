<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shared;

use DateTimeImmutable;
use DateTimeZone;
use LuziApi\Shared\Domain\BusinessCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessCalendarTest extends TestCase
{
    public function testPublicHolidaysCoverFixedAndEasterBasedDays(): void
    {
        $holidays = BusinessCalendar::publicHolidays(2026);

        self::assertCount(11, $holidays);
        foreach ([
            '2026-01-01', '2026-05-01', '2026-05-08', '2026-07-14',
            '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25',
            '2026-04-06', // lundi de Pâques (Pâques 2026 = 5 avril)
        ] as $day) {
            self::assertContains($day, $holidays);
        }
    }

    /**
     * Seconde année, avec Pâques indépendamment connue (28 mars 2027), pour
     * vérifier le computus ET les trois fêtes mobiles — dont l'Ascension (+39)
     * et le lundi de Pentecôte (+50), jamais contrôlés sinon.
     */
    public function testMobileFeastsForASecondYear(): void
    {
        $holidays = BusinessCalendar::publicHolidays(2027);

        self::assertCount(11, $holidays);
        self::assertContains('2027-03-29', $holidays); // lundi de Pâques
        self::assertContains('2027-05-06', $holidays); // Ascension (Pâques + 39)
        self::assertContains('2027-05-17', $holidays); // lundi de Pentecôte (Pâques + 50)
    }

    #[DataProvider('days')]
    public function testIsBusinessDay(string $date, bool $expected): void
    {
        self::assertSame($expected, BusinessCalendar::isBusinessDay($this->parisDate($date)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function days(): iterable
    {
        yield 'vendredi ouvré' => ['2026-01-02', true];
        yield 'samedi' => ['2026-01-03', false];
        yield 'dimanche' => ['2026-01-04', false];
        yield 'jour de l an' => ['2026-01-01', false];
        yield 'lundi de Pâques' => ['2026-04-06', false];
    }

    #[DataProvider('additions')]
    public function testAddBusinessDays(string $start, int $days, string $expected): void
    {
        self::assertSame(
            $expected,
            BusinessCalendar::addBusinessDays($this->parisDate($start), $days)->format('Y-m-d'),
        );
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function additions(): iterable
    {
        yield 'zéro jour inchangé' => ['2026-01-02', 0, '2026-01-02'];
        yield 'nombre négatif : sans effet' => ['2026-01-02', -1, '2026-01-02'];
        yield 'saut du week-end' => ['2026-01-02', 1, '2026-01-05'];
        yield 'saut week-end + lundi de Pâques' => ['2026-04-03', 1, '2026-04-07'];
    }

    private function parisDate(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('Europe/Paris'));
    }
}
