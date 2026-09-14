<?php

declare(strict_types=1);

namespace LuziApi\Newsletter\Infrastructure\Brevo;

use DateTimeImmutable;
use LuziApi\Newsletter\Application\Port\SubscriberDirectory;
use LuziApi\Newsletter\Domain\Subscriber;
use LuziApi\Newsletter\Domain\SubscriptionStatus;
use Throwable;

/**
 * Répertoire d'abonnés adossé à Brevo, en LECTURE SEULE. La clé API vit dans l'option
 * WordPress `sib_api_key_v3` (posée par le plugin officiel). Les inscrits sont les
 * contacts de la liste (e-mail) ; un abonné SMS porte en plus l'attribut `SMS`.
 *
 * Résilient par conception : toute indisponibilité (pas de clé, erreur réseau, réponse
 * inattendue) rend une liste vide / un état null plutôt que de casser l'admin. Les
 * appels sont mis en cache par transient pour ne pas marteler l'API à chaque page.
 */
final class BrevoSubscriberDirectory implements SubscriberDirectory
{
    private const API = 'https://api.brevo.com/v3';
    private const CACHE_PREFIX = 'luziapi_brevo_subs_';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $listId,
        private readonly int $cacheTtl = 300,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function all(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . 'list_' . $this->listId;
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $this->hydrateAll($cached);
        }

        $rows = [];
        $offset = 0;
        $limit = 500;
        do {
            $data = $this->get('/contacts/lists/' . $this->listId . '/contacts?limit=' . $limit . '&offset=' . $offset);
            $contacts = is_array($data) && isset($data['contacts']) && is_array($data['contacts']) ? $data['contacts'] : [];
            foreach ($contacts as $contact) {
                if (is_array($contact)) {
                    $rows[] = $contact;
                }
            }
            $offset += $limit;
        } while (count($contacts) === $limit && $offset < 20000);

        set_transient($cacheKey, $rows, $this->cacheTtl);

        return $this->hydrateAll($rows);
    }

    public function statusFor(?string $email, ?string $phone): ?SubscriptionStatus
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $contact = null;
        if (null !== $email && '' !== trim($email)) {
            $contact = $this->contact(rawurlencode(strtolower(trim($email))), 'email_id');
        }
        if (null === $contact && null !== $phone && '' !== trim($phone)) {
            $contact = $this->contact(rawurlencode(trim($phone)), 'phone_id');
        }
        if (! is_array($contact)) {
            return null;
        }

        $listIds = isset($contact['listIds']) && is_array($contact['listIds']) ? $contact['listIds'] : [];
        $inList = in_array($this->listId, array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $listIds), true);
        $emailBlacklisted = (bool) ($contact['emailBlacklisted'] ?? false);
        $smsBlacklisted = (bool) ($contact['smsBlacklisted'] ?? false);

        return new SubscriptionStatus(
            $inList && ! $emailBlacklisted,
            '' !== $this->smsAttribute($contact) && ! $smsBlacklisted,
        );
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return list<Subscriber>
     */
    private function hydrateAll(array $rows): array
    {
        $subscribers = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $email = isset($row['email']) && is_string($row['email']) ? $row['email'] : '';
            $sms = $this->smsAttribute($row);
            // Un abonné SMS-seul (sans e-mail) reste un abonné : on ne saute que les
            // contacts sans e-mail ET sans SMS.
            if ('' === $email && '' === $sms) {
                continue;
            }
            $smsBlacklisted = (bool) ($row['smsBlacklisted'] ?? false);

            $subscribers[] = new Subscriber(
                $email,
                '' !== $sms && ! $smsBlacklisted,
                '' !== $sms ? $sms : null,
                $this->parseDate($row['createdAt'] ?? null),
            );
        }

        usort($subscribers, static fn (Subscriber $a, Subscriber $b): int => strcmp($a->email, $b->email));

        return $subscribers;
    }

    /**
     * @param array<array-key, mixed> $contact
     */
    private function smsAttribute(array $contact): string
    {
        $attributes = isset($contact['attributes']) && is_array($contact['attributes']) ? $contact['attributes'] : [];
        $sms = $attributes['SMS'] ?? '';

        return is_string($sms) ? trim($sms) : '';
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function contact(string $identifier, string $identifierType): ?array
    {
        $data = $this->get('/contacts/' . $identifier . '?identifierType=' . $identifierType);

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function get(string $path): ?array
    {
        $response = wp_remote_get(self::API . $path, [
            'timeout' => 8,
            'headers' => [
                'api-key' => $this->apiKey,
                'accept'  => 'application/json',
            ],
        ]);

        if ($response instanceof \WP_Error) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }
}
