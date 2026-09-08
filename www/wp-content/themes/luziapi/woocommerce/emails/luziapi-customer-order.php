<?php

/**
 * E-mail HTML commun aux notifications client natives conservées.
 *
 * @var \WC_Order $order
 * @var string    $email_heading
 * @var string    $additional_content
 * @var bool      $sent_to_admin
 * @var bool      $plain_text
 * @var \WC_Email $email
 */

defined('ABSPATH') || exit;

$presentation = luziapi_native_customer_email_presentation(
    $email,
    $order,
    [
        'partial_refund' => isset($partial_refund) && (bool) $partial_refund,
        'customer_note'  => isset($customer_note) ? (string) $customer_note : '',
    ]
);

$email_label    = $presentation['email_label'];
$message_lines  = $presentation['message_lines'];
$highlight_text = $presentation['highlight_text'];
$action_url     = $presentation['action_url'];
$action_label   = $presentation['action_label'];
$closing_line   = $presentation['closing_line'];

$common         = luziapi_customer_email_common_data($order);
$newsletter_url = $common['newsletter_url'];
$site_url       = $common['site_url'];
$logo_url       = $common['logo_url'];
$contact        = $common['contact'];
$cgv_url        = $common['cgv_url'];
$cgv_version    = $common['cgv_version'];
$withdrawal_url = $common['withdrawal_url'];
$mediation_url  = $common['mediation_url'];
$mediator_url   = $common['mediator_url'];

require __DIR__ . '/luziapi-customer-order-status.php';
