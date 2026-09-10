<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use LuziApi\OrderTracking\Application\Port\TrackingSessionCookie;
use LuziApi\OrderTracking\Application\Service\TrackingSession;
use Psr\Log\LoggerInterface;

final readonly class WordPressTrackingSessionCookie implements TrackingSessionCookie
{
    public const NAME = 'luziapi_order_tracking';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function read(): string
    {
        $token = isset($_COOKIE[self::NAME]) ? (string) wp_unslash($_COOKIE[self::NAME]) : '';

        return 1 === preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ? $token : '';
    }

    public function write(TrackingSession $session): void
    {
        $written = setcookie(self::NAME, $session->token, [
            'expires' => $session->expiresAt->getTimestamp(),
            'path' => defined('COOKIEPATH') && '' !== COOKIEPATH ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (false === $written) {
            // En-têtes déjà envoyés : le navigateur ne reçoit pas le cookie et le
            // lien magique (usage unique) vient d'être consommé → utilisateur bloqué.
            $this->logger->error('Suivi commande : cookie de session non posé.', [
                'headers_sent' => headers_sent(),
            ]);
        }
        $_COOKIE[self::NAME] = $session->token;
    }

    public function clear(): void
    {
        setcookie(self::NAME, '', [
            'expires' => time() - 3600,
            'path' => defined('COOKIEPATH') && '' !== COOKIEPATH ? COOKIEPATH : '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::NAME]);
    }
}
