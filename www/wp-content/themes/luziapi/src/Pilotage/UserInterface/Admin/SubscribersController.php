<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

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
                ['label' => 'Abonnés e-mail', 'value' => (string) $view->emailCount],
                ['label' => 'Abonnés SMS', 'value' => (string) $view->smsCount],
            ],
            'subscribers'   => array_map(
                static fn (Subscriber $subscriber): array => [
                    'email' => $subscriber->email,
                    'sms'   => $subscriber->smsSubscribed,
                    'phone' => $subscriber->phone ?? '',
                    'date'  => null !== $subscriber->subscribedAt
                        ? wp_date('d/m/Y', $subscriber->subscribedAt->getTimestamp())
                        : '',
                ],
                $view->subscribers,
            ),
            'brevo_url'     => 'https://app.brevo.com/contact',
        ]);
    }
}
