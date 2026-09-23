<?php

declare(strict_types=1);

namespace LuziApi\Shop\Infrastructure\WordPress\Admin;

use LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots\AdjustLoyaltyPotsCommand;
use LuziApi\Loyalty\Application\Command\AdjustLoyaltyPots\AdjustLoyaltyPotsHandler;
use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesCommand;
use LuziApi\Loyalty\Application\Command\MergeLoyaltyIdentities\MergeLoyaltyIdentitiesHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyHandler;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\GetCustomerLoyaltyQuery;
use LuziApi\Loyalty\Domain\Gateway\LoyaltyIdentityLinks;
use LuziApi\Loyalty\Domain\LoyaltyEntry;
use LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionCommand;
use LuziApi\Newsletter\Application\Command\UpdateSubscription\UpdateSubscriptionHandler;
use LuziApi\Newsletter\Domain\Gateway\SubscriberDirectory;
use LuziApi\Shared\Domain\ValueObject\NormalizedPhone;
use LuziApi\Shared\Infrastructure\Wp;
use LuziApi\Shop\Application\Activity\ActivityRecorder;
use LuziApi\Shop\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountCommand;
use LuziApi\Shop\Application\Command\ApplyThankYouDiscount\ApplyThankYouDiscountHandler;
use LuziApi\Shop\Application\Command\AssignCustomerCategory\AssignCustomerCategoryCommand;
use LuziApi\Shop\Application\Command\AssignCustomerCategory\AssignCustomerCategoryHandler;
use LuziApi\Shop\Application\Command\SaveCustomerProfile\SaveCustomerProfileCommand;
use LuziApi\Shop\Application\Command\SaveCustomerProfile\SaveCustomerProfileHandler;
use LuziApi\Shop\Application\Query\GetCustomerDirectory\GetCustomerDirectoryHandler;
use LuziApi\Shop\Application\Query\GetCustomerDirectory\GetCustomerDirectoryQuery;
use LuziApi\Shop\Domain\Activity\ActivityCategory;
use LuziApi\Shop\Domain\Customer\CustomerBilling;
use LuziApi\Shop\Domain\Customer\CustomerCategory;
use LuziApi\Shop\Domain\Customer\CustomerProfile;
use LuziApi\Shop\Domain\Customer\CustomerTimelineEntry;
use LuziApi\Shop\Domain\Sales\OrderSnapshot;
use LuziApi\Shop\Domain\Sales\ThankYouDiscount;
use LuziApi\Shop\Domain\Sales\ThankYouDiscountType;
use Throwable;
use Timber\Timber;

final readonly class CustomersController
{
    use SurfacesActionErrors;

    private const STATUS_LABELS = [
        'pending'          => 'En attente de paiement',
        'on-hold'          => 'Règlement à vérifier',
        'processing'       => 'À préparer',
        'out-for-delivery' => 'En cours de livraison',
        'ready-for-pickup' => 'Prête au retrait',
        'completed'        => 'Terminée',
        'cancelled'        => 'Annulée',
        'refunded'         => 'Remboursée',
        'failed'           => 'Échouée',
    ];

    public function __construct(
        private GetCustomerDirectoryHandler $getCustomers,
        private AssignCustomerCategoryHandler $assignCategory,
        private ActivityRecorder $activity,
        private ?GetCustomerLoyaltyHandler $getLoyalty = null,
        private ?ApplyThankYouDiscountHandler $applyDiscount = null,
        private ?AdjustLoyaltyPotsHandler $adjustPots = null,
        private ?SubscriberDirectory $subscribers = null,
        private ?SaveCustomerProfileHandler $saveProfile = null,
        private ?UpdateSubscriptionHandler $subscriptions = null,
        private ?MergeLoyaltyIdentitiesHandler $mergeIdentities = null,
        private ?LoyaltyIdentityLinks $identityLinks = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_assign_customer_category', [$this, 'assignCategory']);
        add_action('admin_post_luziapi_apply_thankyou_discount', [$this, 'applyThankYouDiscount']);
        add_action('admin_post_luziapi_adjust_loyalty_pots', [$this, 'adjustLoyaltyPots']);
        add_action('admin_post_luziapi_save_customer_profile', [$this, 'saveCustomerProfile']);
        add_action('admin_post_luziapi_update_subscription', [$this, 'updateSubscription']);
        add_action('admin_post_luziapi_merge_loyalty_customers', [$this, 'mergeLoyaltyCustomers']);
        add_action('admin_post_luziapi_unlink_loyalty_customer', [$this, 'unlinkLoyaltyCustomer']);
    }

    public function updateSubscription(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_update_subscription');
        $rawCustomer = wp_unslash($_POST['customer_id'] ?? '');
        $customerId = is_string($rawCustomer) ? sanitize_key($rawCustomer) : '';

        try {
            if (! $this->subscriptions instanceof UpdateSubscriptionHandler) {
                throw new \RuntimeException('Gestion des abonnements indisponible.');
            }
            $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));
            if (! $directory->selectedCustomer instanceof CustomerProfile) {
                throw new \InvalidArgumentException('Client introuvable.');
            }
            $customer = $directory->selectedCustomer;
            // Source unique : les coordonnées de la fiche (fiche dédiée ou dernière
            // commande) — pour pouvoir créer un contact absent de Brevo (« Ajouter »).
            $billing = $this->billingObjectFor($customer);
            $rawPhone = '' !== $billing->phone ? $billing->phone : ($customer->phones[0] ?? '');
            $phone = '' !== $rawPhone ? (NormalizedPhone::fromString($rawPhone)?->international() ?? $rawPhone) : '';
            $email = '' !== $billing->email ? $billing->email : ($customer->emails[0] ?? '');
            $confirmed = $this->subscriptions->handle(new UpdateSubscriptionCommand(
                $email,
                $phone,
                isset($_POST['sub_email']),
                isset($_POST['sub_sms']),
                $billing->firstName,
                $billing->lastName,
            ));
            $this->rememberDetail('customers', sprintf(
                'Confirmé côté Brevo — e-mail : %s · SMS : %s',
                $confirmed->emailSubscribed ? 'abonné' : 'désabonné',
                $confirmed->smsSubscribed ? 'abonné' : 'désabonné',
            ));
            $this->activity->record(
                ActivityCategory::Customer,
                'customer_subscription_updated',
                'customer_subscription',
                null,
                'Abonnement client mis à jour et confirmé (Brevo)',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'subscription_updated');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'customer_subscription_update_failed',
                'customer_subscription',
                null,
                'Mise à jour d’abonnement client échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'subscription_error');
        }
    }

    public function saveCustomerProfile(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_save_customer_profile');
        $rawCustomer = wp_unslash($_POST['customer_id'] ?? '');
        $customerId = is_string($rawCustomer) ? sanitize_key($rawCustomer) : '';

        try {
            if (! $this->saveProfile instanceof SaveCustomerProfileHandler) {
                throw new \RuntimeException('Édition de la fiche client indisponible.');
            }
            $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));
            if (! $directory->selectedCustomer instanceof CustomerProfile) {
                throw new \InvalidArgumentException('Client introuvable.');
            }
            $identityIds = $directory->selectedCustomer->identityIds;
            $this->saveProfile->handle(new SaveCustomerProfileCommand(
                $identityIds,
                $this->readBilling(),
                get_current_user_id(),
            ));
            // La catégorie est éditée dans le MÊME formulaire (une seule sauvegarde).
            $rawCategory = wp_unslash($_POST['category'] ?? '');
            $category = CustomerCategory::tryFrom(is_string($rawCategory) ? sanitize_key($rawCategory) : '');
            if ($category instanceof CustomerCategory) {
                $this->assignCategory->handle(new AssignCustomerCategoryCommand($identityIds, $category, get_current_user_id()));
            }
            $this->activity->record(
                ActivityCategory::Customer,
                'customer_profile_saved',
                'customer_profile',
                null,
                'Fiche client mise à jour',
                [],
                get_current_user_id(),
            );
            // L'identité ne change pas (la fiche surcharge l'affichage sans réécrire
            // les commandes) : on revient sur la fiche du client.
            $this->redirectAfterCategory($customerId, 'profile_updated');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'customer_profile_save_failed',
                'customer_profile',
                null,
                'Mise à jour de la fiche client échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'profile_error');
        }
    }

    private function readBilling(): CustomerBilling
    {
        $text = static function (string $field): string {
            $raw = wp_unslash($_POST[$field] ?? '');

            return is_string($raw) ? sanitize_text_field($raw) : '';
        };
        $rawEmail = wp_unslash($_POST['billing_email'] ?? '');
        $rawCountry = wp_unslash($_POST['billing_country'] ?? '');

        return new CustomerBilling(
            $text('billing_first_name'),
            $text('billing_last_name'),
            $text('billing_company'),
            $text('billing_address_1'),
            $text('billing_address_2'),
            $text('billing_postcode'),
            $text('billing_city'),
            is_string($rawCountry) ? strtoupper(sanitize_text_field($rawCountry)) : '',
            is_string($rawEmail) ? sanitize_email($rawEmail) : '',
            $text('billing_phone'),
        );
    }

    public function adjustLoyaltyPots(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_adjust_loyalty_pots');
        $customerId = sanitize_key(wp_unslash(Wp::str($_POST['customer_id'] ?? '')));

        try {
            if (! $this->adjustPots instanceof AdjustLoyaltyPotsHandler) {
                throw new \RuntimeException('Loyalty adjustment is not available.');
            }
            $amount = absint(Wp::str($_POST['pots_amount'] ?? 0));
            $direction = 'remove' === sanitize_key(wp_unslash(Wp::str($_POST['pots_direction'] ?? 'add'))) ? -1 : 1;
            $reason = sanitize_text_field(wp_unslash(Wp::str($_POST['pots_reason'] ?? '')));
            $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));
            $key = $directory->selectedCustomer instanceof CustomerProfile ? ($directory->selectedCustomer->identityIds[0] ?? '') : '';
            if ($amount <= 0 || '' === $key) {
                throw new \InvalidArgumentException('Invalid loyalty adjustment request.');
            }
            $this->adjustPots->handle(new AdjustLoyaltyPotsCommand($key, $direction * $amount, $reason, get_current_user_id()));
            $this->redirectAfterCategory($customerId, 'pots_adjusted');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'loyalty_adjust_failed',
                'customer',
                null,
                'Ajustement manuel des pots de fidélité échoué',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'pots_error');
        }
    }

    /**
     * Fusionne le client de la fiche avec un autre : leurs clés d'identité rejoignent
     * un même groupe, pour le cas « même personne, deux e-mails sans téléphone commun »
     * que l'auto-alimentation ne peut pas relier seule. La fusion agrège la fidélité ;
     * elle n'écrit rien sur les commandes.
     */
    public function mergeLoyaltyCustomers(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_merge_loyalty_customers');
        $customerId = sanitize_key(wp_unslash(Wp::str($_POST['customer_id'] ?? '')));

        try {
            $targetId = sanitize_key(wp_unslash(Wp::str($_POST['merge_target'] ?? '')));
            $this->performMerge($customerId, $targetId);
            $this->redirectAfterCategory($customerId, 'customers_merged');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'loyalty_merge_failed',
                'customer',
                null,
                'Fusion de clients fidélité échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'merge_error');
        }
    }

    /**
     * Cœur de la fusion (sans redirection, pour être pilotable en test) : résout les
     * `identityIds` des deux clients et fusionne. Lève une exception si la requête est
     * invalide ou un client introuvable.
     *
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function performMerge(string $customerId, string $targetId): void
    {
        if (! $this->mergeIdentities instanceof MergeLoyaltyIdentitiesHandler) {
            throw new \RuntimeException('Identity merge is not available.');
        }
        if ('' === $targetId || $targetId === $customerId) {
            throw new \InvalidArgumentException('Invalid merge request.');
        }
        $keysA = $this->identityIdsFor($customerId);
        $keysB = $this->identityIdsFor($targetId);
        if ([] === $keysA || [] === $keysB) {
            throw new \InvalidArgumentException('Unknown customer(s) to merge.');
        }
        $this->mergeIdentities->handle(new MergeLoyaltyIdentitiesCommand($keysA, $keysB));
    }

    /**
     * Cœur de la défusion (sans redirection, pour être pilotable en test) : détache les
     * clés du client de son groupe. Lève une exception si le client est introuvable.
     *
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function performUnlink(string $customerId): void
    {
        if (! $this->identityLinks instanceof LoyaltyIdentityLinks) {
            throw new \RuntimeException('Identity links are not available.');
        }
        $keys = $this->identityIdsFor($customerId);
        if ([] === $keys) {
            throw new \InvalidArgumentException('Unknown customer to unlink.');
        }
        $this->identityLinks->unlink($keys);
    }

    /**
     * Clés d'identité (`identityIds`) d'un client à partir de son identifiant de profil.
     *
     * @return list<string>
     */
    private function identityIdsFor(string $customerId): array
    {
        $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));

        return $directory->selectedCustomer instanceof CustomerProfile ? $directory->selectedCustomer->identityIds : [];
    }

    /**
     * Autres clients de la page courante proposés à la fusion (le client affiché exclu).
     * Vide tant qu'aucune fiche n'est ouverte.
     *
     * @param list<CustomerProfile> $customers
     *
     * @return list<array{id: string, name: string}>
     */
    private function mergeCandidates(array $customers, ?CustomerProfile $selected): array
    {
        if (! $selected instanceof CustomerProfile) {
            return [];
        }

        $candidates = [];
        foreach ($customers as $customer) {
            if ($customer->id === $selected->id) {
                continue;
            }
            $name = '' !== $customer->name
                ? $customer->name
                : ($customer->primaryEmail() ?: $customer->primaryPhone() ?: 'Client de passage');
            $candidates[] = ['id' => $customer->id, 'name' => $name];
        }

        return $candidates;
    }

    /**
     * Détache le client de la fiche d'un regroupement d'identités (défusion), pour
     * corriger une fusion manuelle erronée. Ses clés reforment un groupe à part.
     */
    public function unlinkLoyaltyCustomer(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_unlink_loyalty_customer');
        $customerId = sanitize_key(wp_unslash(Wp::str($_POST['customer_id'] ?? '')));

        try {
            $this->performUnlink($customerId);
            $this->redirectAfterCategory($customerId, 'customer_unlinked');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'loyalty_unlink_failed',
                'customer',
                null,
                'Défusion de client fidélité échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'unlink_error');
        }
    }

    /**
     * Vrai si le client de la fiche est regroupé avec des clés au-delà des siennes
     * (fusion) — auquel cas on propose la défusion.
     */
    private function customerIsLinked(?CustomerProfile $selected): bool
    {
        if (! $selected instanceof CustomerProfile || ! $this->identityLinks instanceof LoyaltyIdentityLinks) {
            return false;
        }
        $ids = array_values(array_unique($selected->identityIds));
        if ([] === $ids) {
            return false;
        }

        return count($this->identityLinks->expand($ids)) > count($ids);
    }

    public function applyThankYouDiscount(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_apply_thankyou_discount');
        $customerId = sanitize_key(wp_unslash(Wp::str($_POST['customer_id'] ?? '')));

        try {
            if (! $this->applyDiscount instanceof ApplyThankYouDiscountHandler) {
                throw new \RuntimeException('Thank-you discount is not available.');
            }
            $orderId = absint(Wp::str($_POST['order_id'] ?? 0));
            $discount = $this->readDiscount();
            if ($orderId <= 0 || ! $discount instanceof ThankYouDiscount) {
                throw new \InvalidArgumentException('Invalid thank-you discount request.');
            }
            $this->applyDiscount->handle(new ApplyThankYouDiscountCommand($orderId, $discount, get_current_user_id()));
            $this->redirectAfterCategory($customerId, 'discount_applied');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'thankyou_discount_failed',
                'order',
                null,
                'Remise remerciement échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'discount_error');
        }
    }

    private function readDiscount(): ?ThankYouDiscount
    {
        $type = sanitize_key(wp_unslash(Wp::str($_POST['discount_type'] ?? '')));
        if ('' === $type) {
            return null;
        }
        $raw = sanitize_text_field(wp_unslash(Wp::str($_POST['discount_value'] ?? '')));
        if (ThankYouDiscountType::Percent->value === $type) {
            return ThankYouDiscount::fromInput($type, absint($raw));
        }

        return ThankYouDiscount::fromInput($type, (int) round((float) str_replace(',', '.', $raw) * 100));
    }

    public function render(): void
    {
        $this->assertPermission();

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash(Wp::str($_GET['s']))) : '';
        $page = isset($_GET['paged']) ? absint(Wp::str($_GET['paged'])) : 1;
        $customerId = isset($_GET['customer']) ? sanitize_key(wp_unslash(Wp::str($_GET['customer']))) : '';
        $category = isset($_GET['category'])
            ? CustomerCategory::tryFrom(sanitize_key(wp_unslash(Wp::str($_GET['category']))))
            : null;
        $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery($search, $page, 50, $customerId, $category));
        $pageUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers');
        $quickSaleUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale');

        Timber::render('@luziapi_admin/pilotage/customers.twig', [

            'pilotage_tabs' => PilotageTabs::links('customers'),
            'page_url'          => $pageUrl,
            'dashboard_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'receipts_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts'),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'products_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'     => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'    => $quickSaleUrl,
            'activity_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'loyalty_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty'),
            'orders_url'        => admin_url('admin.php?page=wc-orders'),
            'new_order_url'     => $quickSaleUrl,
            'new_order_for_customer_url' => $directory->selectedCustomer
                ? add_query_arg('customer', $directory->selectedCustomer->id, $quickSaleUrl)
                : '',
            'action_url'        => admin_url('admin-post.php'),
            'category_nonce'    => wp_create_nonce('luziapi_assign_customer_category'),
            'discount_nonce'    => wp_create_nonce('luziapi_apply_thankyou_discount'),
            'adjust_pots_nonce' => wp_create_nonce('luziapi_adjust_loyalty_pots'),
            'profile_nonce'     => wp_create_nonce('luziapi_save_customer_profile'),
            'address_nonce'     => wp_create_nonce('luziapi_address_search'),
            'subscription_nonce' => wp_create_nonce('luziapi_update_subscription'),
            'merge_nonce'       => wp_create_nonce('luziapi_merge_loyalty_customers'),
            'merge_candidates'  => $this->mergeCandidates($directory->customers, $directory->selectedCustomer),
            'unlink_nonce'      => wp_create_nonce('luziapi_unlink_loyalty_customer'),
            'customer_is_linked' => $this->customerIsLinked($directory->selectedCustomer),
            'search'            => $search,
            'selected_category' => $category instanceof CustomerCategory ? $category->value : '',
            'categories'        => array_map(
                static fn (CustomerCategory $customerCategory): array => [
                    'value' => $customerCategory->value,
                    'label' => $customerCategory->label(),
                ],
                CustomerCategory::cases(),
            ),
            'total_customers'   => $directory->totalCustomers,
            'customers'         => array_map($this->formatCustomer(...), $directory->customers),
            'selected_customer' => $directory->selectedCustomer
                ? $this->formatSelectedCustomer($directory->selectedCustomer)
                : null,
            'pagination'        => $this->pagination(
                $pageUrl,
                $search,
                $category instanceof CustomerCategory ? $category->value : '',
                $directory->currentPage,
                $directory->totalPages,
            ),
            'timeline'          => array_map($this->formatTimeline(...), $directory->timeline),
            'notice'            => isset($_GET['customer_notice']) ? sanitize_key(wp_unslash(Wp::str($_GET['customer_notice']))) : '',
            'notice_detail'     => $this->takeErrorDetail('customers'),
        ]);
    }

    public function assignCategory(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_assign_customer_category');
        $customerId = sanitize_key(wp_unslash(Wp::str($_POST['customer_id'] ?? '')));

        try {
            $category = CustomerCategory::tryFrom(sanitize_key(wp_unslash(Wp::str($_POST['category'] ?? ''))));
            if (! $category instanceof CustomerCategory) {
                throw new \InvalidArgumentException('Invalid customer category.');
            }
            $directory = $this->getCustomers->handle(new GetCustomerDirectoryQuery('', 1, 1, $customerId));
            if (! $directory->selectedCustomer instanceof CustomerProfile) {
                throw new \InvalidArgumentException('Unknown customer profile.');
            }
            $this->assignCategory->handle(new AssignCustomerCategoryCommand(
                $directory->selectedCustomer->identityIds,
                $category,
                get_current_user_id(),
            ));
            $this->redirectAfterCategory($customerId, 'category_updated');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('customers', $exception);
            $this->activity->record(
                ActivityCategory::Error,
                'customer_category_update_failed',
                'customer_category',
                null,
                'Modification de la catégorie client échouée',
                [],
                get_current_user_id(),
            );
            $this->redirectAfterCategory($customerId, 'category_error');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCustomer(CustomerProfile $customer): array
    {
        $lastOrder = $customer->lastOrder();
        $sourceLabels = function_exists('luziapi_order_source_options') ? luziapi_order_source_options() : [];

        return [
            'id'           => $customer->id,
            'name'         => $customer->name,
            'city'         => $customer->city,
            'emails'       => $customer->emails,
            'phones'       => array_map(static fn (string $phone): array => [
                'label' => $phone,
                'href'  => NormalizedPhone::fromString($phone)?->international() ?? $phone,
            ], $customer->phones),
            'last_order'   => $this->formatOrder($lastOrder),
            'orders_count' => $customer->validOrdersCount,
            'total'        => $this->formatMoney($customer->orderedTotal->cents()),
            'collected'    => $this->formatMoney($customer->collectedTotal->cents()),
            'products'     => $customer->favoriteProducts,
            'sources'      => array_map(static fn (string $source): string => $sourceLabels[$source] ?? $source, $customer->sources),
            'category'     => $customer->category->label(),
            'category_key' => $customer->category->value,
            'details_url'  => add_query_arg('customer', $customer->id, admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSelectedCustomer(CustomerProfile $customer): array
    {
        $formatted = $this->formatCustomer($customer);
        $formatted['orders'] = array_map($this->formatOrder(...), $customer->orders);
        $formatted['loyalty'] = $this->formatLoyalty($customer);
        $formatted['subscription'] = $this->subscriptionStatus($customer);
        $formatted['billing'] = $this->billingFor($customer);

        return $formatted;
    }

    /**
     * Coordonnées à afficher et à préremplir dans le formulaire d'édition : la fiche
     * dédiée si elle existe, sinon un brouillon issu de la dernière commande (lecture
     * WooCommerce directe — aucune écriture).
     *
     * @return array<string, string>
     */
    private function billingFor(CustomerProfile $customer): array
    {
        $override = $customer->billingOverride;
        $hasOverride = $override instanceof CustomerBilling && ! $override->isEmpty();
        $billing = $this->billingObjectFor($customer);

        return [
            'first_name' => $billing->firstName,
            'last_name'  => $billing->lastName,
            'company'    => $billing->company,
            'address_1'  => $billing->address1,
            'address_2'  => $billing->address2,
            'postcode'   => $billing->postcode,
            'city'       => $billing->city,
            'country'    => '' !== $billing->country ? $billing->country : 'FR',
            'email'      => $billing->email,
            'phone'      => $billing->phone,
            'has_override' => $hasOverride ? '1' : '',
        ];
    }

    /**
     * Coordonnées de la fiche sous forme d'objet : la fiche dédiée si elle existe,
     * sinon le brouillon issu de la dernière commande.
     */
    private function billingObjectFor(CustomerProfile $customer): CustomerBilling
    {
        $billing = $customer->billingOverride;
        if (! $billing instanceof CustomerBilling || $billing->isEmpty()) {
            $billing = $this->draftFromLastOrder($customer);
        }

        return $billing;
    }

    private function draftFromLastOrder(CustomerProfile $customer): CustomerBilling
    {
        $order = wc_get_order($customer->lastOrder()->id);
        if (! $order instanceof \WC_Order) {
            return new CustomerBilling(
                '',
                '',
                '',
                '',
                '',
                '',
                $customer->city,
                'FR',
                $customer->emails[0] ?? '',
                $customer->phones[0] ?? '',
            );
        }

        return new CustomerBilling(
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_company(),
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_postcode(),
            $order->get_billing_city(),
            '' !== $order->get_billing_country() ? $order->get_billing_country() : 'FR',
            $order->get_billing_email(),
            $order->get_billing_phone(),
        );
    }

    /**
     * État d'abonnement (Brevo, lecture seule) du client affiché. `available` = false
     * quand le répertoire n'est pas configuré (dev sans clé) : l'encart est alors masqué.
     *
     * @return array{available: bool, known: bool, email: bool, sms: bool, writable: bool, has_phone: bool}
     */
    private function subscriptionStatus(CustomerProfile $customer): array
    {
        if (! $this->subscribers instanceof SubscriberDirectory || ! $this->subscribers->isConfigured()) {
            return ['available' => false, 'known' => false, 'email' => false, 'sms' => false, 'writable' => false, 'has_phone' => false];
        }

        $email = $customer->emails[0] ?? null;
        $rawPhone = $customer->phones[0] ?? null;
        $phone = null !== $rawPhone ? (NormalizedPhone::fromString($rawPhone)?->international() ?? $rawPhone) : null;

        $status = $this->subscribers->statusFor($email, $phone);

        return [
            'available' => true,
            'known'     => null !== $status,
            'email'     => null !== $status && $status->emailSubscribed,
            'sms'       => null !== $status && $status->smsSubscribed,
            // Inscriptible seulement si l'écriture Brevo est câblée ET qu'on a un e-mail
            // (identifiant du contact). Sans e-mail, l'encart reste en lecture seule.
            'writable'  => $this->subscriptions instanceof UpdateSubscriptionHandler && null !== $email && '' !== $email,
            'has_phone' => null !== $phone && '' !== $phone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatLoyalty(CustomerProfile $customer): ?array
    {
        if (! $this->getLoyalty instanceof GetCustomerLoyaltyHandler) {
            return null;
        }

        $loyalty = $this->getLoyalty->handle(new GetCustomerLoyaltyQuery($customer->identityIds));

        return [
            'net_pots'          => $loyalty->netPots,
            'rewards_available' => $loyalty->rewardsAvailable,
            'rewards_acquired'  => $loyalty->rewardsAcquired,
            'pots_in_progress'  => $loyalty->potsTowardNextReward,
            'pots_until_next'   => $loyalty->potsUntilNextReward,
            'pots_per_reward'   => $loyalty->potsPerReward,
            'entries'           => array_map($this->formatLoyaltyEntry(...), $loyalty->entries),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function formatLoyaltyEntry(LoyaltyEntry $entry): array
    {
        if (0 !== $entry->rightsDelta) {
            // Mouvement d'avantage (pot offert / rendu) : afficher les avantages.
            $movement = sprintf('%+d avantage', $entry->rightsDelta);
        } else {
            $movement = sprintf('%+d pot', $entry->potsDelta);
        }

        return [
            'date'   => Wp::str(wp_date('d/m/Y à H:i', $entry->occurredAt->getTimestamp())),
            'label'  => $entry->type->label(),
            'pots'   => $movement,
            'reason' => $entry->reason,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function formatOrder(OrderSnapshot $order): array
    {
        return [
            'id'     => (string) $order->id,
            'number' => $order->number,
            'url'    => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->id),
            'date'   => Wp::str(wp_date('d/m/Y à H:i', $order->createdAt->getTimestamp())),
            'status' => self::STATUS_LABELS[$order->status] ?? $order->status,
            'total'  => $this->formatMoney($order->total->cents()),
        ];
    }

    /** @return array<string, mixed> */
    private function formatTimeline(CustomerTimelineEntry $entry): array
    {
        return [
            'date'         => wp_date('d/m/Y à H:i', $entry->occurredAt->getTimestamp()),
            'content'      => $entry->content,
            'public'       => $entry->public,
            'visibility'   => $entry->public ? 'Note client' : 'Note privée',
            'order_number' => $entry->orderNumber,
            'order_url'    => admin_url('admin.php?page=wc-orders&action=edit&id=' . $entry->orderId),
        ];
    }

    private function pagination(string $pageUrl, string $search, string $category, int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        return (string) paginate_links([
            'base'      => add_query_arg(['s' => $search, 'category' => $category, 'paged' => '%#%'], $pageUrl),
            'format'    => '',
            'current'   => $currentPage,
            'total'     => $totalPages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function redirectAfterCategory(string $customerId, string $notice): never
    {
        wp_safe_redirect(add_query_arg([
            'customer' => $customerId,
            'customer_notice' => $notice,
        ], admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers')));
        exit;
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }
}
