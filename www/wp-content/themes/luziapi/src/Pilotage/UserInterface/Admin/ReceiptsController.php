<?php

declare(strict_types=1);

namespace LuziApi\Pilotage\UserInterface\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use LuziApi\Pilotage\Application\Activity\ActivityRecorder;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptCommand;
use LuziApi\Pilotage\Application\Command\RecordReceipt\RecordReceiptHandler;
use LuziApi\Pilotage\Application\Command\ReverseReceipt\ReverseReceiptCommand;
use LuziApi\Pilotage\Application\Command\ReverseReceipt\ReverseReceiptHandler;
use LuziApi\Pilotage\Application\Port\Clock;
use LuziApi\Pilotage\Application\Query\GetReceiptRegister\GetReceiptRegisterHandler;
use LuziApi\Pilotage\Application\Query\GetReceiptRegister\GetReceiptRegisterQuery;
use LuziApi\Pilotage\Domain\Activity\ActivityCategory;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntry;
use LuziApi\Pilotage\Domain\Receipt\ReceiptEntryType;
use LuziApi\Pilotage\Domain\Receipt\ReceiptReconciliation;
use LuziApi\Pilotage\Domain\Receipt\ReceiptRepository;
use Throwable;
use Timber\Timber;

final readonly class ReceiptsController
{
    use SurfacesActionErrors;

    private const PAYMENT_METHODS = [
        'cash'          => 'Espèces',
        'cheque'        => 'Chèque',
        'bank_transfer' => 'Virement bancaire',
        'wero'          => 'Wero',
        'card'          => 'Carte bancaire',
        'other'         => 'Autre',
    ];

    public function __construct(
        private GetReceiptRegisterHandler $getRegister,
        private RecordReceiptHandler $recordReceipt,
        private ReverseReceiptHandler $reverseReceipt,
        private ReceiptRepository $receipts,
        private Clock $clock,
        private ActivityRecorder $activity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_luziapi_record_receipt', [$this, 'record']);
        add_action('admin_post_luziapi_reverse_receipt', [$this, 'reverse']);
        add_action('admin_post_luziapi_export_receipts', [$this, 'export']);
    }

    public function render(): void
    {
        $this->assertPermission();
        $requestedYear = isset($_GET['year']) ? absint($_GET['year']) : null;
        $register = $this->getRegister->handle(new GetReceiptRegisterQuery($requestedYear ?: null));
        $summary = $register->summary;

        Timber::render('@luziapi_admin/pilotage/receipts.twig', [
            'year'                => $summary->year,
            'available_years'     => $register->availableYears,
            'page_url'            => $this->pageUrl($summary->year),
            'dashboard_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG),
            'tax_declaration_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=tax-declaration'),
            'customers_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=customers'),
            'products_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=products'),
            'inventory_url'       => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=inventory'),
            'quick_sale_url'      => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=quick-sale'),
            'activity_url'        => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=activity'),
            'loyalty_url' => admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=loyalty'),
            'orders_url'          => admin_url('admin.php?page=wc-orders'),
            'record_action'       => admin_url('admin-post.php'),
            'record_nonce'        => wp_create_nonce('luziapi_record_receipt'),
            'reverse_nonce'       => wp_create_nonce('luziapi_reverse_receipt'),
            'export_url'          => wp_nonce_url(
                admin_url('admin-post.php?action=luziapi_export_receipts&year=' . $summary->year),
                'luziapi_export_receipts',
            ),
            'now'                 => $this->clock->now()->format('Y-m-d\TH:i'),
            'payment_methods'     => self::PAYMENT_METHODS,
            'metrics'             => [
                ['label' => 'Encaissé', 'value' => $this->formatMoney($summary->collected->cents())],
                ['label' => 'Remboursé / corrigé', 'value' => $this->formatMoney($summary->refunded->cents())],
                ['label' => 'Recettes nettes', 'value' => $this->formatMoney($summary->net->cents())],
                ['label' => 'Écritures', 'value' => (string) count($summary->entries)],
            ],
            'entries'             => array_map($this->formatEntry(...), $summary->entries),
            'reconciliations'     => array_map($this->formatReconciliation(...), $register->reconciliations),
            'notice'              => isset($_GET['receipt_notice']) ? sanitize_key(wp_unslash((string) $_GET['receipt_notice'])) : '',
            'notice_detail'       => $this->takeErrorDetail('receipts'),
        ]);
    }

    public function record(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_record_receipt');

        try {
            $type = ReceiptEntryType::tryFrom(sanitize_key(wp_unslash((string) ($_POST['entry_type'] ?? ''))));
            if (! in_array($type, [ReceiptEntryType::Collection, ReceiptEntryType::Refund], true)) {
                throw new InvalidArgumentException('Type d’écriture invalide.');
            }
            $occurredAt = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i',
                sanitize_text_field(wp_unslash((string) ($_POST['occurred_at'] ?? ''))),
                $this->clock->timezone(),
            );
            if (false === $occurredAt) {
                throw new InvalidArgumentException('Date d’encaissement invalide.');
            }
            $amountCents = (int) round((float) wc_format_decimal(wp_unslash((string) ($_POST['amount'] ?? '0')), 2) * 100);
            $orderId = absint($_POST['order_id'] ?? 0) ?: null;
            if (null !== $orderId && ! wc_get_order($orderId)) {
                throw new InvalidArgumentException('Commande introuvable.');
            }

            $this->recordReceipt->handle(new RecordReceiptCommand(
                $orderId,
                $occurredAt,
                $amountCents,
                sanitize_key(wp_unslash((string) ($_POST['payment_method'] ?? ''))),
                $type,
                sanitize_text_field(wp_unslash((string) ($_POST['description'] ?? ''))),
                get_current_user_id(),
            ));
            $this->redirect((int) $occurredAt->format('Y'), 'recorded');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('receipts', $exception);
            $this->recordFailure('Enregistrement d’une recette échoué');
            $this->redirect((int) $this->clock->now()->format('Y'), 'error');
        }
    }

    public function reverse(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_reverse_receipt');

        try {
            $this->reverseReceipt->handle(new ReverseReceiptCommand(
                absint($_POST['entry_id'] ?? 0),
                sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? ''))),
                get_current_user_id(),
            ));
            $this->redirect(absint($_POST['year'] ?? 0), 'reversed');
        } catch (Throwable $exception) {
            $this->rememberErrorDetail('receipts', $exception);
            $this->recordFailure('Contre-écriture d’une recette échouée');
            $this->redirect(absint($_POST['year'] ?? 0), 'error');
        }
    }

    public function export(): void
    {
        $this->assertPermission();
        check_admin_referer('luziapi_export_receipts');
        $year = absint($_GET['year'] ?? 0) ?: (int) $this->clock->now()->format('Y');
        $summary = $this->getRegister->handle(new GetReceiptRegisterQuery($year))->summary;
        $this->activity->record(
            ActivityCategory::Export,
            'receipts_exported',
            'receipt_register',
            null,
            sprintf('Registre des recettes %d exporté en CSV', $year),
            [],
            get_current_user_id(),
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="registre-recettes-luziapi-' . $year . '.csv"');
        $stream = fopen('php://output', 'wb');
        if (false === $stream) {
            wp_die('Impossible de produire le fichier CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['N°', 'Date', 'Type', 'Commande', 'Moyen', 'Montant EUR', 'Description', 'Contre-écriture de'], ';');
        foreach ($summary->entries as $entry) {
            fputcsv($stream, [
                $entry->sequenceNumber,
                $entry->occurredAt->format('d/m/Y H:i'),
                $entry->type->label(),
                $entry->orderId ?? '',
                self::PAYMENT_METHODS[$entry->paymentMethod] ?? $entry->paymentMethod,
                number_format($entry->amount->cents() / 100, 2, ',', ''),
                $this->csvCell($entry->description),
                $entry->reversalOfId ?? '',
            ], ';');
        }
        fclose($stream);
        exit;
    }

    /** @return array<string, mixed> */
    private function formatEntry(ReceiptEntry $entry): array
    {
        return [
            'id'             => $entry->id,
            'sequence'       => str_pad((string) $entry->sequenceNumber, 6, '0', STR_PAD_LEFT),
            'date'           => wp_date('d/m/Y à H:i', $entry->occurredAt->getTimestamp()),
            'type'           => $entry->type->label(),
            'type_key'       => $entry->type->value,
            'order_id'       => $entry->orderId,
            'order_url'      => $entry->orderId ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $entry->orderId) : '',
            'payment_method' => self::PAYMENT_METHODS[$entry->paymentMethod] ?? $entry->paymentMethod,
            'amount'         => $this->formatMoney($entry->amount->cents()),
            'amount_cents'   => $entry->amount->cents(),
            'description'    => $entry->description,
            'reversal_of'    => $entry->reversalOfId,
            'can_reverse'    => ReceiptEntryType::Reversal !== $entry->type && ! $this->receipts->hasReversalFor($entry->id),
        ];
    }

    private function pageUrl(int $year): string
    {
        return admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG . '&tab=receipts&year=' . $year);
    }

    /** @return array<string, mixed> */
    private function formatReconciliation(ReceiptReconciliation $reconciliation): array
    {
        $order = $reconciliation->order;
        $suggestedDate = $order->paidAt ?? $this->clock->now();
        if ($suggestedDate > $this->clock->now()) {
            $suggestedDate = $this->clock->now();
        }

        return [
            'order_id'          => $order->id,
            'order_number'      => $order->number,
            'order_url'         => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->id),
            'customer'          => $order->customerName,
            'date'              => wp_date('d/m/Y', $order->createdAt->getTimestamp()),
            'expected'          => $this->formatMoney($reconciliation->expected->cents()),
            'recorded'          => $this->formatMoney($reconciliation->recorded->cents()),
            'difference'        => $this->formatMoney($reconciliation->difference->cents()),
            'difference_cents'  => $reconciliation->difference->cents(),
            'suggested_amount'  => number_format(abs($reconciliation->difference->cents()) / 100, 2, '.', ''),
            'suggested_date'    => $suggestedDate->format('Y-m-d\TH:i'),
            'suggested_payment' => 'bacs' === $order->paymentMethod ? 'bank_transfer' : 'cash',
        ];
    }

    private function redirect(int $year, string $notice): never
    {
        wp_safe_redirect(add_query_arg('receipt_notice', $notice, $this->pageUrl($year)));
        exit;
    }

    private function assertPermission(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Vous n’avez pas l’autorisation d’accéder à cette page.', 'luziapi'));
        }
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private function formatMoney(int $cents): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($cents / 100)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function recordFailure(string $summary): void
    {
        $this->activity->record(
            ActivityCategory::Error,
            'receipt_operation_failed',
            'receipt_register',
            null,
            $summary,
            [],
            get_current_user_id(),
        );
    }
}
