<?php

declare(strict_types=1);

namespace LuziApi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du câblage des tests e2e et de leur exécution en CI.
 *
 * Politique (voir CONTRIBUTING.md) : toute fonctionnalité au chemin réel
 * WordPress/WooCommerce a un e2e d'intégration **local** (`make e2e-…-local`) et
 * **prod** (`make e2e-…-prod`), et les e2e locaux **tournent en CI**. Ce test
 * empêche la dérive :
 *  - le runner CI existe et est appelé par le workflow ;
 *  - toute cible Make e2e ne référence que des fichiers qui existent ;
 *  - tout script prod a une cible Make du même nom.
 *
 * Le runner CI (`scripts/e2e-ci.sh`) découvre automatiquement les cibles
 * `e2e-*-local` : un nouvel e2e local tourne donc en CI sans autre modification.
 */
final class E2eWiringTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function makefile(): string
    {
        return (string) file_get_contents(self::root() . '/Makefile');
    }

    public function testCiRunnerExistsAndIsInvokedByTheWorkflow(): void
    {
        self::assertFileExists(self::root() . '/scripts/e2e-ci.sh');
        $workflow = (string) file_get_contents(self::root() . '/.github/workflows/ci.yml');
        self::assertStringContainsString('bash scripts/e2e-ci.sh', $workflow, 'Le workflow CI doit lancer scripts/e2e-ci.sh.');
    }

    public function testAtLeastOneLocalE2eTargetIsDiscoverable(): void
    {
        preg_match_all('/^(e2e-[a-z0-9-]+-local):/m', self::makefile(), $matches);
        self::assertNotEmpty($matches[1], 'Aucune cible e2e-*-local dans le Makefile.');
    }

    public function testEveryToolFileReferencedByTheMakefileExists(): void
    {
        preg_match_all('#tools/(e2e-[A-Za-z0-9._-]+\.php)#', self::makefile(), $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $file) {
            self::assertFileExists(
                self::root() . '/www/wp-content/themes/luziapi/tools/' . $file,
                "Le Makefile référence tools/{$file} qui n'existe pas.",
            );
        }
    }

    public function testEveryScriptReferencedByTheMakefileExists(): void
    {
        preg_match_all('#scripts/(e2e-[A-Za-z0-9._-]+\.sh)#', self::makefile(), $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $file) {
            self::assertFileExists(
                self::root() . '/scripts/' . $file,
                "Le Makefile référence scripts/{$file} qui n'existe pas.",
            );
        }
    }

    public function testEveryProdScriptHasAMatchingMakefileTarget(): void
    {
        $makefile = self::makefile();
        $scripts = glob(self::root() . '/scripts/e2e-*-prod.sh') ?: [];
        self::assertNotEmpty($scripts);
        foreach ($scripts as $path) {
            $target = basename($path, '.sh'); // e2e-<slug>-prod
            self::assertMatchesRegularExpression(
                '/^' . preg_quote($target, '/') . ':/m',
                $makefile,
                "Le script {$target}.sh n'a pas de cible Make `{$target}`.",
            );
        }
    }
}
