<?php
/**
 * Plugin Name: LuziApi — Newsletter auto-envoi (article -> campagne Brevo)
 * Description: À la première publication d'un article, programme (~10 min plus tard) l'envoi automatique d'une campagne e-mail Brevo à la liste « LuziApi Newsletter ». Case « ne pas envoyer » dans l'éditeur. Remplace l'ancienne campagne RSS (retirée de Brevo).
 */

if (!defined('LUZIAPI_BREVO_LIST_ID')) {
    define('LUZIAPI_BREVO_LIST_ID', 2);
}
if (!defined('LUZIAPI_NL_DELAY')) {
    define('LUZIAPI_NL_DELAY', 600); // délai avant envoi (10 min) : marge de sécurité + lecture fiable de la case
}

/* ------------------------------------------------------------------ *
 * Planification à la 1re mise en ligne d'un article (jamais sur update).
 * L'envoi est différé : la case « ne pas envoyer » (metabox) est alors
 * déjà enregistrée, même avec l'éditeur Gutenberg.
 * ------------------------------------------------------------------ */

// Publication depuis l'éditeur.
add_action('wp_after_insert_post', function ($post_id, $post, $update, $post_before) {
    if (!($post instanceof WP_Post)) {
        $post = get_post($post_id);
    }
    if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return;
    }
    if ($post_before instanceof WP_Post && $post_before->post_status === 'publish') {
        return; // déjà publié = simple modification
    }
    luziapi_nl_schedule($post->ID);
}, 20, 4);

// Articles programmés (wp-cron : future -> publish).
add_action('transition_post_status', function ($new, $old, $post) {
    if ($new === 'publish' && $old === 'future' && $post instanceof WP_Post && $post->post_type === 'post') {
        luziapi_nl_schedule($post->ID);
    }
}, 20, 3);

function luziapi_nl_schedule($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (get_post_meta($post_id, '_luziapi_nl_sent', true)) {
        return; // déjà envoyé
    }
    if (wp_next_scheduled('luziapi_nl_send_event', [$post_id])) {
        return; // déjà programmé
    }
    wp_schedule_single_event(time() + LUZIAPI_NL_DELAY, 'luziapi_nl_send_event', [$post_id]);
}

/* ------------------------------------------------------------------ *
 * Exécution différée : vérifie la case « ne pas envoyer » puis envoie.
 * ------------------------------------------------------------------ */
add_action('luziapi_nl_send_event', 'luziapi_nl_send_for_post_id');

function luziapi_nl_send_for_post_id($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return;
    }
    if (get_post_meta($post_id, '_luziapi_nl_sent', true)) {
        return;
    }
    if (get_post_meta($post_id, '_luziapi_nl_optout', true) === '1') {
        luziapi_nl_log('Case « ne pas envoyer » cochée, pas d\'envoi pour #' . $post_id);
        return;
    }
    luziapi_nl_send_for_post($post);
}

/* ------------------------------------------------------------------ *
 * Envoi de la campagne pour un article.
 * ------------------------------------------------------------------ */
function luziapi_nl_send_for_post(WP_Post $post) {
    $key = get_option('sib_api_key_v3');
    if (!$key) {
        luziapi_nl_log('Pas de clé API Brevo, envoi annulé pour #' . $post->ID);
        return;
    }

    $listId  = defined('LUZIAPI_BREVO_LIST_ID') ? (int) LUZIAPI_BREVO_LIST_ID : 2;
    $subject = 'Du nouveau au rucher : ' . wp_strip_all_tags(get_the_title($post));
    $html    = luziapi_nl_render_email($post);

    // 1) Créer la campagne.
    $create = wp_remote_post('https://api.brevo.com/v3/emailCampaigns', [
        'timeout' => 30,
        'headers' => ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => $key],
        'body'    => wp_json_encode([
            'name'        => 'Article — ' . wp_strip_all_tags(get_the_title($post)) . ' (#' . $post->ID . ')',
            'subject'     => $subject,
            'sender'      => ['name' => 'LuziApi', 'email' => 'no-reply@luziapi.fr'],
            'type'        => 'classic',
            'htmlContent' => $html,
            'recipients'  => ['listIds' => [$listId]],
        ]),
    ]);
    if (is_wp_error($create)) {
        luziapi_nl_log('Création KO (#' . $post->ID . ') : ' . $create->get_error_message());
        return;
    }
    $code = (int) wp_remote_retrieve_response_code($create);
    $body = json_decode(wp_remote_retrieve_body($create), true);
    if ($code !== 201 || empty($body['id'])) {
        luziapi_nl_log('Création refusée (#' . $post->ID . ') HTTP ' . $code . ' : ' . wp_remote_retrieve_body($create));
        return;
    }
    $campaignId = (int) $body['id'];

    // 2) Envoyer maintenant.
    $send = wp_remote_post('https://api.brevo.com/v3/emailCampaigns/' . $campaignId . '/sendNow', [
        'timeout' => 30,
        'headers' => ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => $key],
    ]);
    $sendCode = is_wp_error($send) ? 0 : (int) wp_remote_retrieve_response_code($send);
    if ($sendCode !== 204) {
        luziapi_nl_log('Envoi refusé (#' . $post->ID . ', campagne ' . $campaignId . ') HTTP ' . $sendCode . ' : ' . (is_wp_error($send) ? $send->get_error_message() : wp_remote_retrieve_body($send)));
        return;
    }

    // 3) Marquer comme envoyé (anti-doublon définitif).
    update_post_meta($post->ID, '_luziapi_nl_sent', current_time('mysql'));
    update_post_meta($post->ID, '_luziapi_nl_campaign_id', $campaignId);
    luziapi_nl_log('Campagne ' . $campaignId . ' envoyée pour #' . $post->ID);
}

/* ------------------------------------------------------------------ *
 * Case « ne pas envoyer à la newsletter » (metabox sur les articles).
 * ------------------------------------------------------------------ */
add_action('add_meta_boxes', function () {
    add_meta_box('luziapi_nl_box', 'Newsletter LuziApi', 'luziapi_nl_metabox', 'post', 'side', 'default');
});

function luziapi_nl_metabox(WP_Post $post) {
    wp_nonce_field('luziapi_nl_box', 'luziapi_nl_nonce');
    $sent = get_post_meta($post->ID, '_luziapi_nl_sent', true);
    if ($sent) {
        echo '<p style="margin:0;color:#1f5e12;">✓ Déjà envoyé à la newsletter le ' . esc_html($sent) . '.</p>';
        return;
    }
    $optout = get_post_meta($post->ID, '_luziapi_nl_optout', true) === '1';
    echo '<label style="display:block;"><input type="checkbox" name="luziapi_nl_optout" value="1" ' . checked($optout, true, false) . '> Ne pas envoyer cet article à la newsletter</label>';
    echo '<p style="margin:.6em 0 0;color:#666;font-size:11px;">Sinon, l\'article part aux abonnés ~10 min après sa publication.</p>';
}

add_action('save_post_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['luziapi_nl_nonce']) || !wp_verify_nonce($_POST['luziapi_nl_nonce'], 'luziapi_nl_box')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (!empty($_POST['luziapi_nl_optout'])) {
        update_post_meta($post_id, '_luziapi_nl_optout', '1');
    } else {
        delete_post_meta($post_id, '_luziapi_nl_optout');
    }
});

/* ------------------------------------------------------------------ *
 * Génération du HTML de l'e-mail (charte LuziApi, 1 article).
 * ------------------------------------------------------------------ */
function luziapi_nl_render_email(WP_Post $post) {
    $title = esc_html(get_the_title($post));
    $url   = esc_url(get_permalink($post));
    $img   = get_the_post_thumbnail_url($post, 'large');

    $excerpt = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 45, '…');
    $excerpt = esc_html($excerpt);

    $imgBlock = '';
    if ($img) {
        $imgBlock = '<tr><td><img src="' . esc_url($img) . '" alt="" width="520" style="display:block;width:100%;height:auto;border-radius:14px 14px 0 0;"></td></tr>';
    }

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#fbf1da;">'
        . '<table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#fffaf0;border-radius:18px;overflow:hidden;">'
        // En-tête
        . '<tr><td style="background:#2b1d10;padding:30px 40px;text-align:center;">'
        . '<div style="font-family:Georgia,serif;font-size:30px;font-weight:bold;color:#f2c75a;">LuziApi</div>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#f7dd9b;padding-top:6px;">Apiculture artisanale · Luzillé (37)</div>'
        . '</td></tr>'
        // Intro
        . '<tr><td style="padding:32px 40px 6px 40px;">'
        . '<h1 style="margin:0 0 10px 0;font-family:Georgia,serif;font-size:26px;line-height:1.25;color:#432c16;">Des nouvelles du rucher</h1>'
        . '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#3a2917;">Un nouvel article vient d\'être publié sur le site. Bonne lecture&nbsp;!</p>'
        . '</td></tr>'
        // Carte article
        . '<tr><td style="padding:20px 40px 6px 40px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf1da;border:1px solid #e6d2a8;border-radius:14px;overflow:hidden;">'
        . $imgBlock
        . '<tr><td style="padding:20px 22px 22px 22px;">'
        . '<h2 style="margin:0 0 8px 0;font-family:Georgia,serif;font-size:20px;line-height:1.3;color:#432c16;">' . $title . '</h2>'
        . '<p style="margin:0 0 18px 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#3a2917;">' . $excerpt . '</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#e0a124" style="border-radius:999px;">'
        . '<a href="' . $url . '" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#432c16;text-decoration:none;border-radius:999px;">Lire la suite &rarr;</a>'
        . '</td></tr></table>'
        . '</td></tr></table></td></tr>'
        // Pied
        . '<tr><td style="background:#2b1d10;padding:26px 40px;text-align:center;">'
        . '<p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#f7dd9b;">LuziApi · 1 rue des Trois Cheminées, 37150 Luzillé<br>'
        . '<a href="tel:+33632853493" style="color:#f7dd9b;text-decoration:none;">06 32 85 34 93</a> · '
        . '<a href="mailto:luziapi37150@gmail.com" style="color:#f7dd9b;text-decoration:none;">luziapi37150@gmail.com</a></p>'
        . '<p style="margin:12px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#b89a6a;">'
        . '<a href="{{ unsubscribe }}" style="color:#f7dd9b;text-decoration:underline;">Se désinscrire</a> · '
        . '<a href="https://www.luziapi.fr/politique-de-confidentialite/" style="color:#f7dd9b;text-decoration:underline;">Confidentialité</a></p>'
        . '</td></tr>'
        . '</table></body></html>';
}

/* ------------------------------------------------------------------ *
 * Journal (diagnostic).
 * ------------------------------------------------------------------ */
function luziapi_nl_log($msg) {
    @file_put_contents(
        WP_CONTENT_DIR . '/uploads/luziapi-nl-send.log',
        gmdate('c') . ' | ' . $msg . "\n",
        FILE_APPEND
    );
}
