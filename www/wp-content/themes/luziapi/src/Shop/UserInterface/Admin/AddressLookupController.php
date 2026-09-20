<?php

declare(strict_types=1);

namespace LuziApi\Shop\UserInterface\Admin;

use LuziApi\Shop\Application\Query\SearchAddress\SearchAddressHandler;
use LuziApi\Shop\Application\Query\SearchAddress\SearchAddressQuery;
use LuziApi\Shop\Domain\Address\AddressSuggestion;

/**
 * Endpoint admin-ajax d'autocomplétion d'adresse (Base Adresse Nationale), utilisé par
 * le formulaire de fiche client. Réservé aux gestionnaires de commandes, protégé par
 * nonce. Renvoie une liste JSON de suggestions normalisées.
 */
final readonly class AddressLookupController
{
    public const ACTION = 'luziapi_address_search';
    public const NONCE = 'luziapi_address_search';

    public function __construct(private SearchAddressHandler $handler)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'search']);
    }

    public function search(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => 'Accès refusé.'], 403);
        }
        check_ajax_referer(self::NONCE);

        $raw = wp_unslash($_GET['q'] ?? '');
        $query = is_string($raw) ? sanitize_text_field($raw) : '';
        $suggestions = $this->handler->handle(new SearchAddressQuery($query));

        wp_send_json_success(array_map(static fn (AddressSuggestion $suggestion): array => [
            'label'    => $suggestion->label,
            'street'   => $suggestion->street,
            'postcode' => $suggestion->postcode,
            'city'     => $suggestion->city,
        ], $suggestions));
    }
}
