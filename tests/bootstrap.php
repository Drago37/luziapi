<?php

declare(strict_types=1);

/*
 * Bootstrap des tests des fonctions pures LuziApi.
 *
 * Au *chargement* du fichier, luziapi-newsletter-autosend.php n'appelle qu'une
 * seule fonction WordPress : add_action(). On la remplace par un stub no-op pour
 * pouvoir inclure le plugin hors WordPress et tester ses fonctions SMS pures
 * (normalisation GSM, comptage de segments, composition du message livré, etc.).
 *
 * Les fonctions qui dépendent réellement de WordPress (get_post_meta,
 * wp_get_shortlink, WP_Post…) ne sont pas testées ici : elles se contentent
 * d'assembler des valeurs puis d'appeler les fonctions pures ci-dessus.
 */
if (!function_exists('add_action')) {
    function add_action(...$args): void
    {
    }
}

if (!function_exists('add_filter')) {
    function add_filter(...$args): void
    {
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents(string $value): string
    {
        return strtr($value, [
            'é' => 'e',
            'É' => 'E',
            'è' => 'e',
            'È' => 'E',
            'ê' => 'e',
            'Ê' => 'E',
            'à' => 'a',
            'À' => 'A',
            'ù' => 'u',
            'Ù' => 'U',
            'î' => 'i',
            'Î' => 'I',
            'ô' => 'o',
            'Ô' => 'O',
        ]);
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require __DIR__ . '/../prod-mu-plugins/luziapi-newsletter-autosend.php';
require __DIR__ . '/../www/wp-content/themes/luziapi/inc/order-workflow.php';
require __DIR__ . '/../www/wp-content/themes/luziapi/inc/payment-deadline.php';

/*
 * Stubs WooCommerce minimalistes : juste ce que les fonctions testées lisent sur
 * une commande (moyen de paiement et méthodes d'expédition).
 */
if (!class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var list<string> */
        private array $notes = [];

        /** @param list<Luziapi_Test_Shipping_Method> $shippingMethods */
        public function __construct(
            private string $paymentMethod = '',
            private array $shippingMethods = []
        ) {
        }

        public function get_payment_method(): string
        {
            return $this->paymentMethod;
        }

        /** @return list<Luziapi_Test_Shipping_Method> */
        public function get_shipping_methods(): array
        {
            return $this->shippingMethods;
        }

        public function add_order_note(string $note, int $isCustomerNote = 0): void
        {
            if (0 === $isCustomerNote) {
                $this->notes[] = $note;
            }
        }

        /** @return list<string> */
        public function get_test_notes(): array
        {
            return $this->notes;
        }
    }
}

if (!class_exists('WC_Email')) {
    class WC_Email
    {
        public string $id;

        /** @var object|bool */
        public $object;

        public function __construct(
            string $id = '',
            private bool $customerEmail = false,
            private string $subject = ''
        ) {
            $this->id = $id;
        }

        public function is_customer_email(): bool
        {
            return $this->customerEmail;
        }

        public function get_subject(): string
        {
            return $this->subject;
        }
    }
}

if (!class_exists('Luziapi_Test_Shipping_Method')) {
    class Luziapi_Test_Shipping_Method
    {
        public function __construct(private string $methodId)
        {
        }

        public function get_method_id(): string
        {
            return $this->methodId;
        }
    }
}

require __DIR__ . '/../www/wp-content/themes/luziapi/inc/customer-emails.php';
