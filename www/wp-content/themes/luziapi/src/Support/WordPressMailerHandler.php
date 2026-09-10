<?php

declare(strict_types=1);

namespace LuziApi\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Envoie une alerte par e-mail (via `wp_mail`, donc signée DKIM o2switch) quand
 * un enregistrement de niveau suffisant est journalisé. Un verrou de fréquence
 * limite à une alerte toutes les N secondes pour ne jamais transformer une
 * tempête d'erreurs en tempête d'e-mails. Une alerte qui échoue reste muette
 * (jamais de boucle de journalisation).
 */
final class WordPressMailerHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $throttleFile,
        private readonly int $throttleSeconds = 120,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! function_exists('wp_mail')) {
            return;
        }

        $now = time();
        $last = is_file($this->throttleFile) ? (int) @file_get_contents($this->throttleFile) : 0;
        if ($now - $last < $this->throttleSeconds) {
            return;
        }
        @file_put_contents($this->throttleFile, (string) $now);

        try {
            wp_mail(
                $this->to,
                $this->subject,
                (string) $record->formatted,
                ['Content-Type: text/plain; charset=UTF-8'],
            );
        } catch (\Throwable) {
            // Silence volontaire : une alerte ratée ne doit rien casser ni reboucler.
        }
    }
}
