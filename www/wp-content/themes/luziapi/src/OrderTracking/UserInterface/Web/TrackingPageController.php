<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\UserInterface\Web;

use InvalidArgumentException;
use LuziApi\Loyalty\Application\Query\GetCustomerLoyalty\CustomerLoyaltyView;
use LuziApi\Loyalty\Application\Query\GetLoyaltyForOrders\GetLoyaltyForOrdersHandler;
use LuziApi\OrderTracking\Application\Command\RedeemHistoryLink\RedeemHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RequestHistoryLink\RequestHistoryLinkHandler;
use LuziApi\OrderTracking\Application\Command\RevokeTrackingSession\RevokeTrackingSessionHandler;
use LuziApi\OrderTracking\Application\Command\StartOrderAccess\StartOrderAccessHandler;
use LuziApi\OrderTracking\Application\Port\Clock;
use LuziApi\OrderTracking\Application\Port\TrackingAccessRepository;
use LuziApi\OrderTracking\Application\Port\TrackingSessionCookie;
use LuziApi\OrderTracking\Application\Query\ResolveTrackingSession\ResolveTrackingSessionHandler;
use LuziApi\OrderTracking\Application\View\PublicOrderPage;
use LuziApi\OrderTracking\Application\View\PublicOrderView;
use LuziApi\OrderTracking\Domain\OrderAccessCredentials;
use LuziApi\OrderTracking\Domain\PublicOrderStatus;
use LuziApi\OrderTracking\Infrastructure\WordPress\WordPressTrackingUrlGenerator;
use Throwable;

final readonly class TrackingPageController
{
    private const FORM_NONCE = 'luziapi_order_tracking';

    public function __construct(
        private StartOrderAccessHandler $startOrderAccess,
        private RequestHistoryLinkHandler $requestHistoryLink,
        private RedeemHistoryLinkHandler $redeemHistoryLink,
        private RevokeTrackingSessionHandler $revokeSession,
        private ResolveTrackingSessionHandler $resolveSession,
        private TrackingAccessRepository $access,
        private TrackingSessionCookie $cookie,
        private WordPressTrackingUrlGenerator $urls,
        private Clock $clock,
        private ?GetLoyaltyForOrdersHandler $loyalty = null,
    ) {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleRequest'], 1);
        add_filter('timber/context', [$this, 'addContext'], 30);
        add_filter('wp_robots', [$this, 'noIndex']);
    }

    public function handleRequest(): void
    {
        if (! is_page(WordPressTrackingUrlGenerator::PAGE_SLUG)) {
            return;
        }

        $this->disableCaching();
        $this->access->purgeExpired($this->clock->now());

        $magicToken = isset($_GET['acces']) ? (string) wp_unslash($_GET['acces']) : '';
        if ('' !== $magicToken) {
            $session = $this->redeemHistoryLink->handle($magicToken);
            if (null !== $session) {
                $this->cookie->write($session);
                $this->redirect(['suivi' => 'connecte']);
            }
            $this->redirect(['suivi' => 'lien-invalide']);
        }

        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            return;
        }
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, self::FORM_NONCE)) {
            $this->redirect(['suivi' => 'session-expiree']);
        }
        if ('' !== trim((string) ($_POST['website'] ?? ''))) {
            $this->redirect(['suivi' => 'lien-envoye']);
        }

        $action = sanitize_key(wp_unslash((string) ($_POST['tracking_action'] ?? '')));
        if ('logout' === $action) {
            $token = $this->cookie->read();
            if ('' !== $token) {
                $this->revokeSession->handle($token);
            }
            $this->cookie->clear();
            $this->redirect(['suivi' => 'deconnecte']);
        }

        if ('request_history' === $action) {
            try {
                $this->requestHistoryLink->handle(
                    sanitize_email(wp_unslash((string) ($_POST['history_email'] ?? ''))),
                    $this->remoteAddress(),
                );
                $this->redirect(['suivi' => 'lien-envoye']);
            } catch (InvalidArgumentException) {
                $this->redirect(['suivi' => 'email-invalide']);
            } catch (Throwable) {
                $this->redirect(['suivi' => 'erreur']);
            }
        }

        if ('track_order' === $action) {
            $orderNumber = sanitize_text_field(wp_unslash((string) ($_POST['order_number'] ?? '')));
            try {
                $credentials = new OrderAccessCredentials(
                    $orderNumber,
                    sanitize_email(wp_unslash((string) ($_POST['order_email'] ?? ''))),
                );
                $result = $this->startOrderAccess->handle($credentials, $this->remoteAddress());
                if ('granted' === $result->status && null !== $result->session) {
                    $this->cookie->write($result->session);
                    $this->redirect(['commande' => $result->orderNumber, 'suivi' => 'commande-trouvee']);
                }
                $this->redirect([
                    'commande' => $credentials->orderNumber,
                    'suivi' => 'limited' === $result->status ? 'trop-de-tentatives' : 'commande-introuvable',
                ]);
            } catch (InvalidArgumentException) {
                $this->redirect(['commande' => ltrim($orderNumber, '#'), 'suivi' => 'saisie-invalide']);
            } catch (Throwable) {
                $this->redirect(['suivi' => 'erreur']);
            }
        }

        $this->redirect(['suivi' => 'saisie-invalide']);
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    public function addContext(array $context): array
    {
        if (! is_page(WordPressTrackingUrlGenerator::PAGE_SLUG)) {
            return $context;
        }

        $this->disableCaching();
        $orderNumber = isset($_GET['commande'])
            ? sanitize_text_field(wp_unslash((string) $_GET['commande']))
            : '';
        $page = isset($_GET['page-commandes']) ? max(1, absint($_GET['page-commandes'])) : 1;
        $sessionToken = $this->cookie->read();
        $orders = '' !== $sessionToken
            ? $this->resolveSession->handle($sessionToken, $page, 10, $orderNumber)
            : null;
        if ('' !== $sessionToken && null === $orders) {
            $this->cookie->clear();
        }

        $context['order_tracking'] = [
            'active' => $orders instanceof PublicOrderPage,
            'orders' => $orders instanceof PublicOrderPage
                ? array_map(
                    fn (PublicOrderView $order): array => $this->formatOrderSummary($order, $orders->currentPage),
                    $orders->orders,
                )
                : [],
            'selected_order' => $orders?->selectedOrder instanceof PublicOrderView
                ? $this->formatOrder($orders->selectedOrder)
                : null,
            'total_orders' => $orders instanceof PublicOrderPage ? $orders->totalOrders : 0,
            'pagination' => $orders instanceof PublicOrderPage
                ? $this->pagination($orders, $orderNumber)
                : '',
            'page_url' => $this->urls->pageUrl(),
            'nonce' => wp_create_nonce(self::FORM_NONCE),
            'prefill_order' => ltrim($orderNumber, '#'),
            'notice' => $this->notice(isset($_GET['suivi']) ? sanitize_key(wp_unslash((string) $_GET['suivi'])) : ''),
            'loyalty' => $orders instanceof PublicOrderPage ? $this->loyaltyFor($orders) : null,
        ];

        return $context;
    }

    /**
     * Bloc fidélité du client identifié, agrégé sur toutes ses commandes (les clés
     * d'identité se déduisent des commandes accessibles à la session). Toujours
     * affiché une fois identifié — sert de rappel du programme même à zéro pot.
     *
     * @return array<string, mixed>|null
     */
    private function loyaltyFor(PublicOrderPage $orders): ?array
    {
        if (! $this->loyalty instanceof GetLoyaltyForOrdersHandler) {
            return null;
        }

        $orderIds = array_map(static fn (PublicOrderView $order): int => $order->id, $orders->orders);
        if ([] === $orderIds) {
            return null;
        }

        return $this->formatLoyalty($this->loyalty->handle($orderIds));
    }

    /** @return array<string, mixed> */
    private function formatLoyalty(CustomerLoyaltyView $loyalty): array
    {
        return [
            'net_pots' => $loyalty->netPots,
            'rewards_available' => $loyalty->rewardsAvailable,
            'pots_toward_next' => $loyalty->potsTowardNextReward,
            'pots_until_next' => $loyalty->potsUntilNextReward,
            'pots_per_reward' => $loyalty->potsPerReward,
        ];
    }

    /** @param array<string, bool> $robots
     *  @return array<string, bool>
     */
    public function noIndex(array $robots): array
    {
        if (is_page(WordPressTrackingUrlGenerator::PAGE_SLUG)) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
        }

        return $robots;
    }

    /** @return array<string, mixed> */
    private function formatOrder(PublicOrderView $order): array
    {
        $status = PublicOrderStatus::describe($order->status);

        return $this->formatOrderSummary($order) + [
            'first_name' => $order->firstName,
            'payment_method' => '' !== $order->paymentMethod ? $order->paymentMethod : 'À préciser',
            'fulfillment_label' => $order->fulfillmentLabel,
            'customer_note' => $order->customerNote,
            'steps' => PublicOrderStatus::steps($order->status, $order->fulfillmentMode),
            'cancelled' => 'cancelled' === $status['tone'],
            'lines' => array_map(fn ($line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'total' => $this->formatMoney($line->totalCents, $order->currency),
            ], $order->lines),
            'totals' => array_map(fn ($total): array => [
                'label' => $total->label,
                'amount' => $this->formatMoney($total->amountCents, $order->currency),
            ], $order->totals),
            'updates' => array_map(static fn ($update): array => [
                'type' => $update->type,
                'title' => $update->title,
                'content' => $update->content,
                'date' => wp_date('d/m/Y à H:i', $update->occurredAt->getTimestamp()),
            ], $order->updates),
        ];
    }

    /** @return array<string, mixed> */
    private function formatOrderSummary(PublicOrderView $order, int $currentPage = 1): array
    {
        $status = PublicOrderStatus::describe($order->status);
        $url = $this->urls->forOrderNumber($order->number);
        if ($currentPage > 1) {
            $url = add_query_arg('page-commandes', $currentPage, $url);
        }

        return [
            'number' => $order->number,
            'date' => wp_date('d/m/Y', $order->createdAt->getTimestamp()),
            'status' => $status['label'],
            'tone' => $status['tone'],
            'total' => $this->formatMoney($order->totalCents, $order->currency),
            'url' => $url,
        ];
    }

    private function formatMoney(int $cents, string $currency): string
    {
        return html_entity_decode(
            wp_strip_all_tags(wc_price($cents / 100, ['currency' => $currency])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    private function pagination(PublicOrderPage $orders, string $selectedOrderNumber): string
    {
        if ($orders->totalPages <= 1) {
            return '';
        }

        return (string) paginate_links([
            'base' => add_query_arg([
                'commande' => $selectedOrderNumber,
                'page-commandes' => '%#%',
            ], $this->urls->pageUrl()),
            'format' => '',
            'current' => $orders->currentPage,
            'total' => $orders->totalPages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
    }

    /** @return array{type: string, message: string}|null */
    private function notice(string $code): ?array
    {
        return match ($code) {
            'commande-trouvee' => ['type' => 'success', 'message' => 'Votre commande a bien été retrouvée.'],
            'connecte' => ['type' => 'success', 'message' => 'Accès sécurisé confirmé : voici vos commandes.'],
            'deconnecte' => ['type' => 'success', 'message' => 'Vous avez quitté votre espace de suivi.'],
            'lien-envoye' => ['type' => 'success', 'message' => 'Si cette adresse correspond à des commandes, un lien sécurisé vient d’être envoyé. Pensez à vérifier les courriers indésirables.'],
            'commande-introuvable' => ['type' => 'error', 'message' => 'Aucune commande ne correspond à ces informations. Vérifiez le numéro et l’adresse e-mail utilisés lors de la commande.'],
            'trop-de-tentatives' => ['type' => 'error', 'message' => 'Trop de tentatives ont été effectuées. Réessayez dans une heure ou contactez LuziApi.'],
            'lien-invalide' => ['type' => 'error', 'message' => 'Ce lien est invalide, expiré ou a déjà été utilisé. Vous pouvez en demander un nouveau.'],
            'email-invalide', 'saisie-invalide' => ['type' => 'error', 'message' => 'Vérifiez les informations saisies puis réessayez.'],
            'session-expiree' => ['type' => 'error', 'message' => 'Le formulaire a expiré. Actualisez la page puis réessayez.'],
            'erreur' => ['type' => 'error', 'message' => 'Le suivi est temporairement indisponible. Réessayez plus tard ou contactez LuziApi.'],
            default => null,
        };
    }

    private function remoteAddress(): string
    {
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return 1 === preg_match('/^[0-9a-fA-F:.]{2,45}$/', $address) ? $address : 'unknown';
    }

    /** @param array<string, string> $arguments */
    private function redirect(array $arguments): never
    {
        wp_safe_redirect(add_query_arg($arguments, $this->urls->pageUrl()));
        exit;
    }

    private function disableCaching(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Vary: Cookie', false);
    }
}
