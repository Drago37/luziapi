<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Command\RequestHistoryLink;

use InvalidArgumentException;
use LuziApi\OrderTracking\Application\Port\AccessFingerprint;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\MagicLinkSender;
use LuziApi\OrderTracking\Application\Port\MagicLinkUrlGenerator;
use LuziApi\OrderTracking\Application\Port\OrderTrackingGateway;
use LuziApi\OrderTracking\Application\Port\TokenGenerator;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;

final readonly class RequestHistoryLinkHandler
{
    public const LIFETIME_SECONDS = 900;
    private const EMAIL_LIMIT = 3;
    private const IP_LIMIT = 10;
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private OrderTrackingGateway $orders,
        private TrackingAccessRepository $access,
        private TokenGenerator $tokens,
        private AccessFingerprint $fingerprints,
        private MagicLinkUrlGenerator $urls,
        private MagicLinkSender $sender,
        private Clock $clock,
    ) {
    }

    public function handle(string $email, string $remoteAddress): HistoryLinkRequestResult
    {
        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid customer email.');
        }

        $now = $this->clock->now();
        $allowedByEmail = $this->access->allowAttempt(
            'history_email',
            $this->fingerprints->subject($email),
            self::EMAIL_LIMIT,
            self::WINDOW_SECONDS,
            $now,
        );
        $allowedByIp = $this->access->allowAttempt(
            'history_ip',
            $this->fingerprints->subject($remoteAddress),
            self::IP_LIMIT,
            self::WINDOW_SECONDS,
            $now,
        );
        if (! $allowedByEmail || ! $allowedByIp) {
            return new HistoryLinkRequestResult(false, true);
        }

        $orderIds = array_values(array_unique(array_filter(
            $this->orders->findOrderIdsByEmail($email),
            static fn (int $orderId): bool => $orderId > 0,
        )));
        if ([] === $orderIds) {
            // Canal temporel résiduel accepté : une adresse inconnue répond un peu
            // plus vite (pas de génération de jeton ni d'envoi). Risque tenu pour
            // négligeable — la réponse HTTP est identique et le débit est limité.
            return new HistoryLinkRequestResult(false, false);
        }

        $token = $this->tokens->generate();
        $expiresAt = $now->modify('+' . self::LIFETIME_SECONDS . ' seconds');
        $this->access->issueMagicLink(
            $this->fingerprints->token($token),
            $orderIds,
            $expiresAt,
            $now,
        );
        $sent = $this->sender->send($email, $this->urls->forToken($token), $expiresAt);

        return new HistoryLinkRequestResult($sent, false);
    }
}
