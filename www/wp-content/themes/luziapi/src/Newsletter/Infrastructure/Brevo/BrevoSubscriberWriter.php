<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Infrastructure\Brevo;

use LuziApi\Newsletter\Application\Port\SubscriberWriter;
use LuziApi\Newsletter\Domain\SubscriptionStatus;
use RuntimeException;

/**
 * Écriture des abonnements dans Brevo (create/update contact, liste + attribut SMS +
 * blacklists), suivie d'une RELECTURE de confirmation. Inscription DIRECTE, sans double
 * opt-in. La clé API vit dans l'option `sib_api_key_v3`. Après écriture, le cache de
 * lecture de la liste est purgé pour que la page « Abonnés » reflète le changement.
 */
final class BrevoSubscriberWriter implements SubscriberWriter
{
    private const API = 'https://api.brevo.com/v3';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $listId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function setSubscription(string $email, string $phone, bool $emailSubscribed, bool $smsSubscribed): SubscriptionStatus
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Clé API Brevo absente : abonnement non modifiable.');
        }

        $body = [
            'email'            => $email,
            'listIds'          => [$this->listId],
            'updateEnabled'    => true,
            'emailBlacklisted' => ! $emailSubscribed,
            'smsBlacklisted'   => ! $smsSubscribed,
        ];
        if ('' !== $phone) {
            $body['attributes'] = ['SMS' => $phone];
        }

        $response = wp_remote_post(self::API . '/contacts', [
            'timeout' => 8,
            'headers' => [
                'api-key'      => $this->apiKey,
                'accept'       => 'application/json',
                'content-type' => 'application/json',
            ],
            'body' => (string) wp_json_encode($body),
        ]);
        if ($response instanceof \WP_Error) {
            throw new RuntimeException('Brevo injoignable : ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        // 201 = contact créé, 204 = contact mis à jour ; tout le 2xx est un succès.
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Brevo a refusé la mise à jour (HTTP ' . $code . ').');
        }

        delete_transient('luziapi_brevo_subs_list_' . $this->listId);

        // Relecture : on confirme l'état RÉELLEMENT enregistré, on ne le suppose pas.
        $confirmed = $this->confirm($email);
        if ($confirmed->emailSubscribed !== $emailSubscribed || $confirmed->smsSubscribed !== $smsSubscribed) {
            throw new RuntimeException(
                'Écrit dans Brevo, mais l’état confirmé ne correspond pas à la demande '
                . '(e-mail ' . ($confirmed->emailSubscribed ? 'abonné' : 'désabonné')
                . ', SMS ' . ($confirmed->smsSubscribed ? 'abonné' : 'désabonné') . ').',
            );
        }

        return $confirmed;
    }

    /**
     * Relit le contact et en déduit l'état d'abonnement confirmé.
     */
    private function confirm(string $email): SubscriptionStatus
    {
        $response = wp_remote_get(self::API . '/contacts/' . rawurlencode(strtolower(trim($email))) . '?identifierType=email_id', [
            'timeout' => 8,
            'headers' => ['api-key' => $this->apiKey, 'accept' => 'application/json'],
        ]);
        if ($response instanceof \WP_Error) {
            throw new RuntimeException('Écrit dans Brevo, mais confirmation impossible (relecture réseau) — vérifiez dans Brevo.');
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Écrit dans Brevo, mais confirmation impossible (relecture HTTP ' . $code . ') — vérifiez dans Brevo.');
        }
        $contact = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($contact)) {
            throw new RuntimeException('Écrit dans Brevo, mais réponse de relecture inattendue — vérifiez dans Brevo.');
        }

        $listIds = isset($contact['listIds']) && is_array($contact['listIds']) ? $contact['listIds'] : [];
        $inList = in_array($this->listId, array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $listIds), true);
        $attributes = isset($contact['attributes']) && is_array($contact['attributes']) ? $contact['attributes'] : [];
        $hasSms = isset($attributes['SMS']) && is_string($attributes['SMS']) && '' !== trim($attributes['SMS']);

        return new SubscriptionStatus(
            $inList && false === (bool) ($contact['emailBlacklisted'] ?? false),
            $hasSms && false === (bool) ($contact['smsBlacklisted'] ?? false),
        );
    }
}
