<?php

declare(strict_types=1);

namespace LuziApi\Tests\Shop;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Garde-fou de sécurité : chaque action d'écriture du tableau de pilotage
 * (`admin_post_*` / `wp_ajax_*`) doit vérifier un nonce ET une capability.
 *
 * Le test énumère les actions réellement enregistrées par les contrôleurs
 * d'administration et inspecte le corps de chaque handler ; il n'exécute aucun
 * code WordPress. Une nouvelle action ajoutée sans garde le fait échouer.
 */
final class AdminActionsAreGuardedTest extends TestCase
{
    private const NAMESPACE = 'LuziApi\\Shop\\Infrastructure\\WordPress\\Admin\\';
    private const REGISTRATION = '#add_action\([^;]*?(?:admin_post_|wp_ajax_)[^;]*?\[\s*\$this\s*,\s*[\'"](\w+)[\'"]\s*\]#';
    private const NONCE_CHECK = '/check_admin_referer\(|check_ajax_referer\(/';
    private const CAPABILITY_CHECK = '/current_user_can\(|assertPermission\(/';

    public function testEveryAdminWriteActionChecksNonceAndCapability(): void
    {
        $adminDir = dirname(__DIR__, 2)
            . '/www/wp-content/themes/luziapi/src/Shop/Infrastructure/WordPress/Admin';
        self::assertDirectoryExists($adminDir);

        $guarded = 0;
        foreach (glob($adminDir . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (0 === preg_match_all(self::REGISTRATION, $source, $matches)) {
                continue;
            }

            $reflection = new ReflectionClass(self::NAMESPACE . basename($file, '.php'));
            $lines = file($file) ?: [];

            foreach (array_unique($matches[1]) as $method) {
                $handler = $reflection->getMethod($method);
                $body = implode('', array_slice(
                    $lines,
                    $handler->getStartLine() - 1,
                    $handler->getEndLine() - $handler->getStartLine() + 1,
                ));

                self::assertSame(1, preg_match(self::NONCE_CHECK, $body), sprintf('%s::%s doit vérifier un nonce.', $reflection->getShortName(), $method));
                self::assertSame(1, preg_match(self::CAPABILITY_CHECK, $body), sprintf('%s::%s doit vérifier une capability.', $reflection->getShortName(), $method));
                ++$guarded;
            }
        }

        // Contrôle positif : sans ceci, une regex qui ne matche plus rien ferait
        // passer la garde à vide.
        self::assertGreaterThanOrEqual(14, $guarded, 'Trop peu d’actions d’écriture inspectées — le repérage a dû dériver.');
    }
}
