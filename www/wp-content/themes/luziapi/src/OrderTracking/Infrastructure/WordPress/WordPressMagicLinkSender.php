<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Infrastructure\WordPress;

use DateTimeImmutable;
use LuziApi\OrderTracking\Application\Port\MagicLinkSender;
use Psr\Log\LoggerInterface;

final readonly class WordPressMagicLinkSender implements MagicLinkSender
{
    public function __construct(
        private string $logoUrl,
        private string $siteUrl,
        private LoggerInterface $logger,
    ) {
    }

    public function send(string $email, string $accessUrl, DateTimeImmutable $expiresAt): bool
    {
        $expiry = wp_date('H\hi', $expiresAt->getTimestamp());
        $message = '<!doctype html><html lang="fr"><body style="margin:0;background:#fffaf0;color:#3a2917;font-family:Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:28px 16px">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border:1px solid #e6d2a8;border-radius:18px;overflow:hidden">'
            . '<tr><td align="center" style="background:#2b1d10;padding:24px"><a href="' . esc_url($this->siteUrl) . '"><img src="' . esc_url($this->logoUrl) . '" width="120" alt="LuziApi"></a></td></tr>'
            . '<tr><td style="padding:32px"><p style="margin:0 0 8px;color:#8a5410;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:2px">Accès sécurisé</p>'
            . '<h1 style="margin:0 0 20px;color:#2b1d10;font-family:Georgia,serif;font-size:30px">Retrouvez vos commandes LuziApi</h1>'
            . '<p>Vous avez demandé un accès à l’historique des commandes associées à cette adresse e-mail.</p>'
            . '<p style="text-align:center;margin:28px 0"><a href="' . esc_url($accessUrl) . '" style="display:inline-block;background:#e0a124;color:#432c16;text-decoration:none;font-weight:bold;padding:13px 24px;border-radius:999px">Accéder à mes commandes</a></p>'
            . '<p style="font-size:14px;color:#7d6038">Ce lien est utilisable une seule fois et expire à ' . esc_html($expiry) . '. Après ouverture, votre session restera active pendant 2 heures sur cet appareil.</p>'
            . '<p style="font-size:14px;color:#7d6038">Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail : aucune commande n’a été modifiée.</p>'
            . '</td></tr></table></td></tr></table></body></html>';

        $sent = wp_mail(
            $email,
            'Votre accès sécurisé aux commandes LuziApi',
            $message,
            ['Content-Type: text/html; charset=UTF-8'],
        );

        if (! $sent) {
            // La réponse au visiteur reste volontairement générique (anti-énumération) ;
            // on trace l'échec ici pour qu'un lien jamais délivré ne passe pas inaperçu.
            $this->logger->error('Suivi commande : envoi du lien magique échoué (wp_mail).', [
                'domain' => substr((string) strrchr($email, '@'), 1),
            ]);
        }

        return $sent;
    }
}
