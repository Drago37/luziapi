<?php

/**
 * E-mail client réutilisable pour les étapes métier d'une commande LuziApi.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Luziapi_Order_Status_Email extends \WC_Email
{
    /**
     * Groupe d'affichage dans les réglages WooCommerce.
     *
     * @var string
     */
    public $email_group = 'order-changes';

    private string $defaultSubject;

    private string $defaultHeading;

    private string $message;

    /**
     * @param array{
     *     id: string,
     *     title: string,
     *     description: string,
     *     subject: string,
     *     heading: string,
     *     message: string,
     *     hooks: list<string>
     * } $definition
     */
    public function __construct(array $definition)
    {
        $this->id             = $definition['id'];
        $this->title          = $definition['title'];
        $this->description    = $definition['description'];
        $this->defaultSubject = $definition['subject'];
        $this->defaultHeading = $definition['heading'];
        $this->message        = $definition['message'];
        $this->customer_email = true;
        $this->template_html  = 'emails/luziapi-customer-order-status.php';
        $this->template_plain = 'emails/plain/luziapi-customer-order-status.php';
        $this->template_base  = LUZIAPI_DIR . '/woocommerce/';
        $this->placeholders   = [
            '{order_date}'   => '',
            '{order_number}' => '',
        ];

        foreach ($definition['hooks'] as $hook) {
            add_action($hook, [$this, 'trigger'], 10, 2);
        }

        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return $this->defaultSubject;
    }

    public function get_default_heading(): string
    {
        return $this->defaultHeading;
    }

    /**
     * @param int|mixed             $orderId
     * @param \WC_Order|false|mixed $order
     */
    public function trigger($orderId, $order = false): void
    {
        $this->setup_locale();

        if ($orderId && ! $order instanceof \WC_Order) {
            $order = wc_get_order($orderId);
        }

        if ($order instanceof \WC_Order) {
            $this->object                         = $order;
            $this->recipient                      = $order->get_billing_email();
            $this->placeholders['{order_date}']   = wc_format_datetime($order->get_date_created());
            $this->placeholders['{order_number}'] = $order->get_order_number();
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }

        $this->restore_locale();
    }

    /**
     * @return list<string>
     */
    public function get_message_lines(): array
    {
        if (! $this->object instanceof \WC_Order) {
            return [];
        }

        $orderNumber = $this->object->get_order_number();

        switch ($this->message) {
            case 'on_hold':
                return [
                    sprintf('J’ai bien reçu votre commande n°%s. Elle est actuellement en attente de confirmation du règlement.', $orderNumber),
                    'Sa préparation commencera dès que le paiement aura été confirmé.',
                ];

            case 'processing':
                return [
                    sprintf('Votre commande n°%s est bien confirmée.', $orderNumber),
                    'Je vais maintenant préparer vos pots de miel. Vous recevrez un nouveau message lorsque votre commande sera prête.',
                ];

            case 'out_for_delivery':
                return [
                    sprintf('Votre commande n°%s est prête à être livrée.', $orderNumber),
                    'Je prendrai contact avec vous afin de convenir du jour et de l’heure de la livraison à l’adresse indiquée dans votre commande.',
                    'La livraison est proposée uniquement à Bléré et Luzillé.',
                ];

            case 'ready_for_pickup':
                return [
                    sprintf('Votre commande n°%s est prête.', $orderNumber),
                    sprintf('Le retrait aura lieu à mon domicile, au %s.', luziapi_get_pickup_address()),
                    'Je prendrai contact avec vous afin de convenir du jour et de l’heure du rendez-vous.',
                ];

            case 'completed':
                return [
                    sprintf('Votre commande n°%s a bien été livrée ou retirée.', $orderNumber),
                    'Merci pour votre commande et pour votre confiance.',
                    'À bientôt chez LuziApi !',
                ];
        }

        return [];
    }

    public function get_content_html(): string
    {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => false,
                'email'              => $this,
                'message_lines'      => $this->get_message_lines(),
            ],
            '',
            $this->template_base
        );
    }

    public function get_content_plain(): string
    {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => true,
                'email'              => $this,
                'message_lines'      => $this->get_message_lines(),
            ],
            '',
            $this->template_base
        );
    }
}

/**
 * L'adresse est centralisée dans les réglages de la boutique afin que ce
 * workflow ne la duplique pas.
 */
function luziapi_get_pickup_address(): string
{
    $parts = array_filter([
        trim((string) get_option('woocommerce_store_address')),
        trim((string) get_option('woocommerce_store_address_2')),
        trim(
            (string) get_option('woocommerce_store_postcode')
            . ' '
            . (string) get_option('woocommerce_store_city')
        ),
    ]);

    return implode(', ', $parts);
}
