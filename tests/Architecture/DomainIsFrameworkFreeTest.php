<?php

declare(strict_types=1);

namespace LuziApi\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Garde-fou d'architecture : le domaine de chaque contexte doit rester exempt
 * de WordPress et de WooCommerce (ADR 0001). Ces dépendances vivent dans les
 * adaptateurs d'Infrastructure, jamais dans Domain.
 */
final class DomainIsFrameworkFreeTest extends TestCase
{
    private const FORBIDDEN = '/\$wpdb|\bwc_[a-z]|\bwp_[a-z]|get_option\(|get_post_meta\(|add_action\(|add_filter\(|\bWC_[A-Z]|\bWP_[A-Z]/';

    public function testEveryDomainLayerIsFreeOfWordPressAndWooCommerce(): void
    {
        $src = dirname(__DIR__, 2) . '/www/wp-content/themes/luziapi/src';
        self::assertDirectoryExists($src);

        $offenders = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (1 !== preg_match('#/src/[^/]+/Domain/#', $path)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (is_string($contents) && 1 === preg_match(self::FORBIDDEN, $contents)) {
                $offenders[] = substr($path, (int) strpos($path, '/src/') + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Le domaine doit rester sans WordPress/WooCommerce ; déplacez la dépendance dans un adaptateur d’Infrastructure.'
        );
    }
}
