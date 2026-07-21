<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique SMS de la newsletter (mu-plugin luziapi-newsletter-autosend).
 *
 * Couvre : normalisation typographique -> GSM, détection Unicode, comptage de
 * segments, longueur de la mention « STOP au … », composition du message livré
 * et critère de blocage « tient en 1 seul SMS ».
 */
final class SmsTest extends TestCase
{
    private const LINK = 'https://luziapi.fr/?p=42'; // 24 car. (lien court type)

    /* ------------------------------------------------------------------ *
     * Hypothèses sur les constantes (fondent le calcul de réservation).
     * ------------------------------------------------------------------ */

    public function testStopConstantKeepsPlaceholderForBrevo(): void
    {
        // Le message envoyé à Brevo garde [STOP_CODE] ; Brevo le remplace à l'envoi.
        self::assertStringContainsString('[STOP_CODE]', LUZIAPI_SMS_STOP);
    }

    public function testStopCodeLengthIsFive(): void
    {
        self::assertSame(5, strlen(LUZIAPI_SMS_STOP_CODE));
    }

    public function testStopDeliveredLengthIsFourteen(): void
    {
        // « STOP au 36180 » (espace de tête inclus) = 14 caractères.
        self::assertSame(14, luziapi_sms_stop_len());
    }

    /* ------------------------------------------------------------------ *
     * Normalisation typographique -> alphabet GSM.
     * ------------------------------------------------------------------ */

    /**
     * @param string $in
     * @param string $expected
     */
    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $in, string $expected): void
    {
        self::assertSame($expected, luziapi_sms_normalize($in));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'apostrophe courbe'   => ['l’or', "l'or"],
            'guillemets francais' => ['«reine»', '"reine"'],
            'guillemets anglais'  => ['“miel”', '"miel"'],
            'tiret cadratin'      => ['A — B', 'A - B'],
            'tiret demi-cadratin' => ['A – B', 'A - B'],
            'points suspension'   => ['fin…', 'fin...'],
            'ligature oe'         => ['œuf', 'oeuf'],
            'ligature OE'         => ['ŒUF', 'OEUF'],
            'espace insecable'    => ["a\xC2\xA0b", 'a b'],
            'trim'                => ['  miel  ', 'miel'],
            'accents GSM gardes'  => ['très déjà à où', 'très déjà à où'],
            'circonflexe -> base' => ['goût août fête être', 'gout aout fete etre'],
            'trema -> base'       => ['Noël naïve', 'Noel naive'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Détection de l'encodage (GSM vs Unicode).
     * ------------------------------------------------------------------ */

    /**
     * @param string $in
     * @param bool   $expected
     */
    #[DataProvider('unicodeProvider')]
    public function testIsUnicode(string $in, bool $expected): void
    {
        self::assertSame($expected, luziapi_sms_is_unicode($in));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function unicodeProvider(): array
    {
        return [
            'ascii pur'           => ['Bonjour tout le monde', false],
            'accents GSM autorises' => ['Déjà une très bonne crème à Luzillé', false],
            'circonflexe hors GSM' => ['goût daoût', true],  // û absent du sous-ensemble GSM
            'trema hors GSM'      => ['Noël naïve', true],   // ë et ï absents du sous-ensemble
            'euro hors GSM'       => ['prix 5€', true],      // € non couvert par le compteur
            'emoji'               => ['Miel 😀', true],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Comptage des segments.
     * ------------------------------------------------------------------ */

    /**
     * @param string $in
     * @param int    $expected
     */
    #[DataProvider('segmentsProvider')]
    public function testSegments(string $in, int $expected): void
    {
        self::assertSame($expected, luziapi_sms_segments($in));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function segmentsProvider(): array
    {
        return [
            'vide'            => ['', 1],
            'GSM 160 = 1 seg' => [str_repeat('a', 160), 1],
            'GSM 161 = 2 seg' => [str_repeat('a', 161), 2],
            'GSM 306 = 2 seg' => [str_repeat('a', 306), 2],
            'GSM 307 = 3 seg' => [str_repeat('a', 307), 3],
            'Uni 70 = 1 seg'  => [str_repeat('ï', 70), 1],
            'Uni 71 = 2 seg'  => [str_repeat('ï', 71), 2],
            'Uni 134 = 2 seg' => [str_repeat('ï', 134), 2],
            'Uni 135 = 3 seg' => [str_repeat('ï', 135), 3],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Composition du message livré (code STOP réel substitué).
     * ------------------------------------------------------------------ */

    public function testComposeDeliveredAssemblesTextLinkAndStop(): void
    {
        $msg = luziapi_sms_compose_delivered('Coucou', self::LINK);
        self::assertSame('Coucou ' . self::LINK . ' STOP au 36180', $msg);
    }

    public function testComposeDeliveredSubstitutesPlaceholder(): void
    {
        $msg = luziapi_sms_compose_delivered('Coucou', self::LINK);
        self::assertStringNotContainsString('[STOP_CODE]', $msg);
        self::assertStringContainsString(' STOP au ' . LUZIAPI_SMS_STOP_CODE, $msg);
    }

    public function testComposeDeliveredNormalizesText(): void
    {
        $msg = luziapi_sms_compose_delivered('l’or œuf…', 'L');
        self::assertSame("l'or oeuf... L STOP au 36180", $msg);
    }

    /* ------------------------------------------------------------------ *
     * Critère de blocage : le SMS complet doit tenir en 1 segment.
     * ------------------------------------------------------------------ */

    public function testFitsOneSegmentShortText(): void
    {
        self::assertTrue(luziapi_sms_fits_one_segment('Nouvel article au rucher', self::LINK));
    }

    public function testFitsOneSegmentEmptyTextUsesDefault(): void
    {
        // Texte vide : reste le lien + STOP, largement 1 segment.
        self::assertTrue(luziapi_sms_fits_one_segment('', self::LINK));
    }

    public function testFitsOneSegmentAtBoundary(): void
    {
        // Extras = 1 (espace) + 24 (lien) + 14 (STOP) = 39. Limite GSM 160 -> 121 car. de texte.
        $justFits = str_repeat('a', 121);
        $tooLong  = str_repeat('a', 122);

        self::assertSame(160, mb_strlen(luziapi_sms_compose_delivered($justFits, self::LINK)));
        self::assertTrue(luziapi_sms_fits_one_segment($justFits, self::LINK));
        self::assertFalse(luziapi_sms_fits_one_segment($tooLong, self::LINK));
    }

    public function testFitsOneSegmentUnicodeIsStricter(): void
    {
        // Un caractère hors GSM *non translittéré* (€) force l'encodage Unicode
        // (seuil 70) : le même nombre de caractères tiendrait en GSM (seuil 160).
        $gsm     = str_repeat('a', 40);       // total 79 : 1 segment GSM
        $unicode = str_repeat('a', 39) . '€'; // total 79 : 2 segments Unicode
        self::assertTrue(luziapi_sms_fits_one_segment($gsm, self::LINK));
        self::assertFalse(luziapi_sms_fits_one_segment($unicode, self::LINK));
    }

    public function testCircumflexAndTremaBecomeGsm(): void
    {
        // Après normalisation, â ê î ô û ë ï deviennent des lettres GSM.
        self::assertSame('gout Noel', luziapi_sms_normalize('goût Noël'));
        self::assertFalse(luziapi_sms_is_unicode(luziapi_sms_normalize('goût août Noël naïve fête')));
    }

    public function testCircumflexTextStaysOneSegment(): void
    {
        // 100 « û » -> 100 « u » (GSM) : tient en 1 SMS, là où l'Unicode aurait bloqué.
        self::assertTrue(luziapi_sms_fits_one_segment(str_repeat('û', 100), self::LINK));
    }

    /* ------------------------------------------------------------------ *
     * Cohérence entre le compteur JS (metabox) et le calcul serveur.
     * ------------------------------------------------------------------ */

    public function testCounterReservationMatchesServerLength(): void
    {
        // Le JS calcule : total = longueur(texte normalise) + reserved,
        // avec reserved = strlen(lien) + 1 + luziapi_sms_stop_len().
        // Ce total doit égaler la longueur réelle du message livré (texte ASCII).
        $text     = 'Bonjour tout le monde';
        $reserved = strlen(self::LINK) + 1 + luziapi_sms_stop_len();
        $jsTotal  = strlen(luziapi_sms_normalize($text)) + $reserved;
        $phpTotal = mb_strlen(luziapi_sms_compose_delivered($text, self::LINK));

        self::assertSame($phpTotal, $jsTotal);
    }
}
