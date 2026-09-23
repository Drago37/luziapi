<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use LuziApi\Shop\Domain\Legal\LegalDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalDocumentTest extends TestCase
{
    public function testExposesCurrentVersionLabelAndPdf(): void
    {
        $document = new LegalDocument('2026-09-16-v5', 'Version du 16 septembre 2026', 'LuziApi-CGV-');

        self::assertSame('2026-09-16-v5', $document->currentVersion());
        self::assertSame('Version du 16 septembre 2026', $document->label());
        self::assertSame('LuziApi-CGV-2026-09-16-v5.pdf', $document->currentPdfBasename());
    }

    #[DataProvider('acceptedVersions')]
    public function testPdfBasenameForAcceptedVersion(string $accepted, string $expected): void
    {
        $document = new LegalDocument('2026-09-16-v5', 'label', 'LuziApi-CGV-');

        self::assertSame($expected, $document->pdfBasenameForAcceptedVersion($accepted));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedVersions(): iterable
    {
        yield 'version acceptée valide' => ['2026-09-07', 'LuziApi-CGV-2026-09-07.pdf'];
        yield 'espaces autour recadrés' => ['  2026-09-07  ', 'LuziApi-CGV-2026-09-07.pdf'];
        yield 'version absente : repli courant' => ['', 'LuziApi-CGV-2026-09-16-v5.pdf'];
        yield 'format douteux (slash) : repli courant' => ['2026/09', 'LuziApi-CGV-2026-09-16-v5.pdf'];
        yield 'format douteux (espace interne) : repli courant' => ['v 5', 'LuziApi-CGV-2026-09-16-v5.pdf'];
    }
}
