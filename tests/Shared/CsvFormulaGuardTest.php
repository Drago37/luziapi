<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shared;

use LuziApi\Shared\Domain\CsvFormulaGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvFormulaGuardTest extends TestCase
{
    #[DataProvider('cells')]
    public function testNeutralize(string $input, string $expected): void
    {
        self::assertSame($expected, CsvFormulaGuard::neutralize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'formule =' => ['=SUM(A1:A9)', "'=SUM(A1:A9)"];
        yield 'formule +' => ['+1+1', "'+1+1"];
        yield 'formule @' => ['@cmd', "'@cmd"];
        yield 'tiret en tête (y compris nombre négatif) neutralisé' => ['-5', "'-5"];
        yield 'texte normal inchangé' => ['Miel de printemps', 'Miel de printemps'];
        yield 'chaîne vide inchangée' => ['', ''];
        yield 'signe égal non initial inchangé' => ['a=b', 'a=b'];
        yield 'espace initial : non neutralisé (premier caractère seulement)' => [' =x', ' =x'];
    }
}
