<?php

declare(strict_types=1);

/**
 * Stub PHPStan minimal pour WP-CLI (classe `WP_CLI`), utilisée par les runners CLI
 * de `tools/` (`make …-local`). WP-CLI n'est pas fourni par l'extension
 * phpstan-wordpress ; sans ce stub, chaque `WP_CLI::log()/error()/…` remonterait en
 * « unknown class ». Ce fichier n'est QUE scanné (symboles), jamais exécuté ni
 * analysé — voir `phpstan-tools.neon.dist` (scanFiles + excludePaths).
 *
 * @see https://make.wordpress.org/cli/handbook/references/internal-api/
 */
if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        /** @param string|\WP_Error|\Throwable|\Exception $message */
        public static function error($message, bool $exit = true): void
        {
        }

        public static function success(string $message): void
        {
        }

        /** @param string|\WP_Error $message */
        public static function warning($message): void
        {
        }

        public static function log(string $message): void
        {
        }

        public static function line(string $message = ''): void
        {
        }

        /**
         * @param callable|string        $callable
         * @param array<string, mixed>   $args
         */
        public static function add_command(string $name, $callable, array $args = []): bool
        {
            return true;
        }

        /**
         * @param array<string, mixed> $options
         *
         * @return mixed
         */
        public static function runcommand(string $command, array $options = [])
        {
            return null;
        }

        /**
         * @param array<string, mixed> $assoc_args
         */
        public static function confirm(string $question, array $assoc_args = []): void
        {
        }

        public static function colorize(string $string): string
        {
            return $string;
        }

        public static function halt(int $code): void
        {
        }

        /**
         * @return mixed
         */
        public static function get_config(?string $key = null)
        {
            return null;
        }
    }
}
