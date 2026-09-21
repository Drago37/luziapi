<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress\Admin;

use LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersHandler;
use LuziApi\Newsletter\Application\Query\GetSubscribers\GetSubscribersQuery;
use LuziApi\Newsletter\Domain\Subscriber;
use Timber\Timber;

final readonly class SubscribersController
{
    public function __construct(private GetSubscribersHandler $getSubscribers)
    {
    }

    public function render(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }

        $rawSearch = wp_unslash($_GET['s'] ?? '');
        $search = is_string($rawSearch) ? sanitize_text_field($rawSearch) : '';
        $view = $this->getSubscribers->handle(new GetSubscribersQuery($search));

        Timber::render('@luziapi_admin/pilotage/subscribers.twig', [
            'pilotage_tabs' => PilotageTabs::links('subscribers'),
            'page_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=subscribers'),
            'configured'    => $view->configured,
            'search'        => $search,
            'metrics'       => [
                ['label' => 'Contacts (total)', 'value' => (string) $view->total],
                ['label' => 'Abonnés e-mail', 'value' => (string) $view->emailCount],
                ['label' => 'Abonnés SMS', 'value' => (string) $view->smsCount],
                ['label' => 'Bloqués', 'value' => (string) $view->blockedCount],
            ],
            'subscribers'   => array_map(
                static fn (Subscriber $subscriber): array => [
                    'email'          => $subscriber->email,
                    'email_ok'       => $subscriber->emailSubscribed(),
                    'email_blocked'  => $subscriber->hasEmail() && $subscriber->emailBlacklisted,
                    'phone'          => $subscriber->phone ?? '',
                    'sms_ok'         => $subscriber->smsSubscribed(),
                    'sms_blocked'    => $subscriber->hasSms() && $subscriber->smsBlacklisted,
                    'date'           => null !== $subscriber->subscribedAt
                        ? wp_date('d/m/Y', $subscriber->subscribedAt->getTimestamp())
                        : '',
                ],
                $view->subscribers,
            ),
            'brevo_url'     => 'https://app.brevo.com/contact',
        ]);
    }
}
