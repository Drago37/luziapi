<?php

/**
 * Génère la copie durable des CGV depuis le rendu WordPress local.
 *
 * Les styles courants sont intégrés dans le document avant impression et les
 * liens locaux sont remplacés par leur équivalent public. Le site Docker doit
 * être accessible sur http://localhost:8080.
 */

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
    exit(1);
}

$root       = dirname(__DIR__);
$sourceUrl  = 'http://localhost:8080/conditions-generales-de-vente/';
$publicBase = 'https://www.luziapi.fr';
$cssPath    = $root . '/www/wp-content/themes/luziapi/assets/css/main.css';
$logoPath   = $root . '/www/wp-content/themes/luziapi/assets/img/logo-email.png';
$outputPath = $argv[1] ?? $root . '/www/wp-content/themes/luziapi/assets/docs/LuziApi-CGV-2026-09-08-v3.pdf';
$chromePath = '/usr/bin/google-chrome';

$html = file_get_contents($sourceUrl);
$css  = file_get_contents($cssPath);
$logo = file_get_contents($logoPath);
if (! is_string($html) || '' === $html || ! is_string($css) || '' === $css || ! is_string($logo) || '' === $logo) {
    fwrite(STDERR, "Impossible de charger la page locale, sa feuille de style ou son logo.\n");
    exit(1);
}

if (! is_executable($chromePath)) {
    fwrite(STDERR, "Google Chrome est requis pour générer le PDF.\n");
    exit(1);
}

$html = str_replace('</head>', "<style>\n" . $css . "\n</style>\n</head>", $html);
$html = str_replace('http://localhost:8080', $publicBase, $html);
$logoDataUrl = 'data:image/png;base64,' . base64_encode($logo);
$html = preg_replace_callback(
    '/(<img class="legal-print-logo" src=")[^"]+("[^>]*>)/',
    static fn (array $matches): string => $matches[1] . $logoDataUrl . $matches[2],
    $html,
    1
);

$temporaryDirectory = sys_get_temp_dir() . '/luziapi-cgv-' . bin2hex(random_bytes(6));
if (! mkdir($temporaryDirectory, 0700) && ! is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Impossible de créer le dossier temporaire.\n");
    exit(1);
}

$htmlPath = $temporaryDirectory . '/cgv.html';
file_put_contents($htmlPath, $html);

$command = implode(' ', [
    escapeshellarg($chromePath),
    '--headless=new',
    '--no-sandbox',
    '--disable-gpu',
    '--run-all-compositor-stages-before-draw',
    '--virtual-time-budget=4000',
    '--no-pdf-header-footer',
    '--print-to-pdf-no-header',
    '--print-to-pdf=' . escapeshellarg($outputPath),
    escapeshellarg('file://' . $htmlPath),
]);

exec($command, $commandOutput, $exitCode);

unlink($htmlPath);
rmdir($temporaryDirectory);

if (0 !== $exitCode || ! is_file($outputPath)) {
    fwrite(STDERR, "Échec de la génération du PDF.\n");
    exit(1);
}

echo $outputPath . "\n";
