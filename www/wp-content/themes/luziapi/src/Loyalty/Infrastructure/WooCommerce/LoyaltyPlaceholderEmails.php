<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WooCommerce;

use LuziApi\Loyalty\Domain\LoyaltyIdentity;

/**
 * E-mails « placeholder » exclus de l'auto-liaison d'identité : typiquement l'adresse
 * de la boutique, qu'un opérateur pourrait saisir pour des passages en Vente. Les
 * relier auto-fusionnerait des clients sans rapport (l'auto-liaison ne garde que
 * l'axe téléphone, cf. `WordPressLoyaltyIdentityLinks::autoLink`). Filtrable via
 * `luziapi_loyalty_placeholder_emails` pour ajouter une adresse fourre-tout connue.
 */
final class LoyaltyPlaceholderEmails
{
    /**
     * Clés e-mail (hachées) à ne JAMAIS auto-lier.
     *
     * @return list<string>
     */
    public static function emailKeys(): array
    {
        $raw = [];
        foreach (['admin_email', 'woocommerce_email_from_address', 'woocommerce_store_email'] as $option) {
            $value = get_option($option, '');
            if (is_string($value) && '' !== $value) {
                $raw[] = $value;
            }
        }

        $filtered = apply_filters('luziapi_loyalty_placeholder_emails', $raw);
        if (! is_array($filtered)) {
            $filtered = $raw;
        }

        $keys = [];
        foreach ($filtered as $email) {
            if (! is_string($email)) {
                continue;
            }
            $key = LoyaltyIdentity::contactKeys($email, '')['email'];
            if (null !== $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
