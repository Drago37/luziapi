<?php
/**
 * Plugin Name: LuziApi — Newsletter auto-envoi (article -> campagne Brevo)
 * Description: À la première publication d'un article, programme (~10 min plus tard) l'envoi à la liste « LuziApi Newsletter » via Brevo. Choix par article (cases dans l'éditeur) : par e-mail et/ou par SMS.
 */

if (!defined('LUZIAPI_BREVO_LIST_ID')) {
    define('LUZIAPI_BREVO_LIST_ID', 2);
}
if (!defined('LUZIAPI_NL_DELAY')) {
    define('LUZIAPI_NL_DELAY', 600); // délai avant envoi (10 min) : marge de sécurité + lecture fiable de la case
}
if (!defined('LUZIAPI_SMS_STOP')) {
    // Mention de désinscription obligatoire (France). Brevo remplace [STOP_CODE]
    // par le numéro court réel à l'envoi. Ajoutée automatiquement à chaque SMS.
    define('LUZIAPI_SMS_STOP', ' STOP au [STOP_CODE]');
}
if (!defined('LUZIAPI_SMS_STOP_CODE')) {
    // Exemple de code court réel (5 car.), utilisé UNIQUEMENT pour estimer la
    // longueur du SMS *livré* dans le compteur de la metabox. Brevo injecte son
    // propre numéro à la place de [STOP_CODE] ; seule sa longueur compte ici.
    define('LUZIAPI_SMS_STOP_CODE', '36180');
}
if (!defined('LUZIAPI_SMS_MAX_SEGMENTS')) {
    // Nombre maximal de segments SMS autorisés par message (1 crédit / segment /
    // personne). Au-delà, l'envoi SMS est refusé (surcoût + risque de troncature).
    define('LUZIAPI_SMS_MAX_SEGMENTS', 2);
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
    $email = get_post_meta($post_id, '_luziapi_nl_email', true);
    $sms   = get_post_meta($post_id, '_luziapi_nl_sms', true);
    // Repli si aucun choix enregistré (publication hors éditeur) : e-mail par défaut.
    if ($email === '' && $sms === '') {
        $email = '1';
    }
    if ($email === '1' && !get_post_meta($post_id, '_luziapi_nl_sent', true)) {
        luziapi_nl_send_for_post($post);
    }
    if ($sms === '1' && !get_post_meta($post_id, '_luziapi_nl_sms_sent', true)) {
        luziapi_nl_send_sms_for_post($post);
    }
}

/* ------------------------------------------------------------------ *
 * Objet de l'e-mail : personnalisé (metabox) sinon défaut générique.
 * ------------------------------------------------------------------ */
function luziapi_nl_email_subject(WP_Post $post) {
    $custom = trim((string) get_post_meta($post->ID, '_luziapi_nl_email_subject', true));
    if ($custom !== '') {
        return $custom;
    }
    return 'Du nouveau au rucher : ' . wp_strip_all_tags(get_the_title($post));
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
    $subject = luziapi_nl_email_subject($post);
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
 * Construction du message SMS : texte personnalisé (ou titre par défaut)
 * + lien court, normalisé en alphabet GSM pour tenir en 1 segment.
 * ------------------------------------------------------------------ */
function luziapi_nl_sms_message(WP_Post $post) {
    // Lien court (~24 car.) : redirige vers l'article.
    $link = wp_get_shortlink($post->ID);
    if (!$link) {
        $link = get_permalink($post);
    }

    // Texte personnalisé (metabox) sinon repli sur le titre.
    $custom = trim((string) get_post_meta($post->ID, '_luziapi_nl_sms_text', true));
    if ($custom !== '') {
        $text = luziapi_sms_normalize($custom);
    } else {
        $title = luziapi_sms_normalize(wp_strip_all_tags(get_the_title($post)));
        if (function_exists('mb_strlen') && mb_strlen($title) > 70) {
            $title = rtrim(mb_substr($title, 0, 67)) . '...';
        }
        $text = 'LuziApi : ' . $title;
    }

    return $text . ' ' . $link . LUZIAPI_SMS_STOP;
}

/**
 * Remplace les caractères typographiques usuels par leurs équivalents ASCII
 * (tirets longs, apostrophes/guillemets courbes, points de suspension, espaces
 * insécables…) pour éviter le passage en Unicode qui divise par ~2 la capacité
 * d'un SMS. Les lettres accentuées de l'alphabet GSM (é è à ù ç ü ö ä ñ…) sont
 * conservées ; celles qui n'y figurent pas (â ê î ô û ë ï) sont translittérées
 * vers leur lettre de base pour rester en 1 SMS GSM (l'accent est perdu dans le
 * SMS uniquement, pas dans l'article).
 */
function luziapi_sms_normalize($s) {
    $map = [
        "\xE2\x80\x99" => "'",   // ’
        "\xE2\x80\x98" => "'",   // ‘
        "\xE2\x80\x9C" => '"',   // “
        "\xE2\x80\x9D" => '"',   // ”
        "\xE2\x80\x9E" => '"',   // „
        "\xE2\x80\x93" => '-',   // – (en dash)
        "\xE2\x80\x94" => '-',   // — (em dash)
        "\xE2\x80\xA6" => '...', // …
        "\xC2\xA0"     => ' ',   // espace insécable
        "\xE2\x80\xAF" => ' ',   // espace fine insécable
        "\xC2\xAB"     => '"',   // «
        "\xC2\xBB"     => '"',   // »
        "\xC5\x93"     => 'oe',  // œ
        "\xC5\x92"     => 'OE',  // Œ
        "\xC3\xA6"     => 'ae',  // æ
        "\xC3\x86"     => 'AE',  // Æ
        "\xC2\xB7"     => '-',   // ·
        // Lettres hors alphabet GSM : translittérées vers la lettre de base.
        "\xC3\xA2"     => 'a',   // â
        "\xC3\x82"     => 'A',   // Â
        "\xC3\xAA"     => 'e',   // ê
        "\xC3\x8A"     => 'E',   // Ê
        "\xC3\xAE"     => 'i',   // î
        "\xC3\x8E"     => 'I',   // Î
        "\xC3\xB4"     => 'o',   // ô
        "\xC3\x94"     => 'O',   // Ô
        "\xC3\xBB"     => 'u',   // û
        "\xC3\x9B"     => 'U',   // Û
        "\xC3\xAB"     => 'e',   // ë
        "\xC3\x8B"     => 'E',   // Ë
        "\xC3\xAF"     => 'i',   // ï
        "\xC3\x8F"     => 'I',   // Ï
    ];
    return trim(strtr($s, $map));
}

/**
 * Longueur de la mention de désinscription telle qu'elle sera *livrée* : Brevo
 * remplace [STOP_CODE] par un code court réel (~5 car.). Sert à réserver la place
 * dans le compteur de la metabox pour que le texte saisi tienne compte du suffixe
 * ajouté automatiquement — pas de nombre en dur à maintenir.
 */
function luziapi_sms_stop_len() {
    return strlen(str_replace('[STOP_CODE]', LUZIAPI_SMS_STOP_CODE, LUZIAPI_SMS_STOP));
}

/**
 * Vrai si le texte contient au moins un caractère hors du sous-ensemble GSM
 * autorisé (ce qui force l'encodage Unicode et réduit ~ de moitié la capacité
 * d'un SMS). Le sous-ensemble couvre les lettres françaises usuelles ; il est
 * volontairement identique à la regex du compteur JS de la metabox, pour que le
 * comptage à l'écran et le comptage serveur coïncident.
 */
function luziapi_sms_is_unicode($s) {
    return (bool) preg_match('/[^\x{20}-\x{7E}£¥§¿¡éèàùçìòñüöäÄÖÑÜÉ]/u', (string) $s);
}

/**
 * Nombre de segments SMS d'un message *déjà normalisé et livré* (code STOP réel).
 * GSM : 160 car. sur 1 segment, puis 153 par segment ; Unicode : 70 puis 67.
 * Comptage par caractère (comme le compteur JS) — suffisant ici, le lien et la
 * mention STOP étant en ASCII.
 */
function luziapi_sms_segments($s) {
    $s   = (string) $s;
    $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    $uni = luziapi_sms_is_unicode($s);
    $single = $uni ? 70 : 160;
    $multi  = $uni ? 67 : 153;
    return $len <= $single ? 1 : (int) ceil($len / $multi);
}

/**
 * Message SMS *livré* (code STOP réel substitué à [STOP_CODE]) assemblé à partir
 * du texte de base et du lien. Fonction pure (aucun appel WordPress) : c'est elle
 * qui sert de référence au comptage des segments côté serveur et aux tests.
 */
function luziapi_sms_compose_delivered($text, $link) {
    $text = luziapi_sms_normalize((string) $text);
    $stop = str_replace('[STOP_CODE]', LUZIAPI_SMS_STOP_CODE, LUZIAPI_SMS_STOP);
    return $text . ' ' . (string) $link . $stop;
}

/**
 * Critère de blocage : vrai si le SMS complet (texte + lien + mention STOP
 * livrée) tient en 1 seul segment. Au-delà, l'envoi SMS est refusé (surcoût et
 * risque de troncature).
 */
function luziapi_sms_fits_one_segment($text, $link) {
    return luziapi_sms_segments(luziapi_sms_compose_delivered($text, $link)) <= 1;
}

/**
 * Nombre de segments du SMS tel qu'il sera livré pour un article donné
 * (dépend de WordPress : lien court + texte/titre). S'appuie sur le message
 * d'envoi en y substituant le code STOP réel pour refléter la longueur livrée.
 */
function luziapi_nl_sms_segments_for_post(WP_Post $post) {
    $delivered = str_replace('[STOP_CODE]', LUZIAPI_SMS_STOP_CODE, luziapi_nl_sms_message($post));
    return luziapi_sms_segments($delivered);
}

/* ------------------------------------------------------------------ *
 * Envoi SMS de l'article (campagne SMS Brevo à la liste).
 * ------------------------------------------------------------------ */
function luziapi_nl_send_sms_for_post(WP_Post $post) {
    $key = get_option('sib_api_key_v3');
    if (!$key) {
        luziapi_nl_log('Pas de clé API Brevo, SMS annulé pour #' . $post->ID);
        return;
    }
    $listId  = defined('LUZIAPI_BREVO_LIST_ID') ? (int) LUZIAPI_BREVO_LIST_ID : 2;
    $title   = wp_strip_all_tags(get_the_title($post));
    $content = luziapi_nl_sms_message($post);

    // Garde-fou : ne jamais dépasser le plafond de segments (surcoût + troncature
    // possible). La saisie est déjà bloquée en amont (metabox + save_post) ;
    // c'est ici la dernière barrière avant l'appel à Brevo.
    $segments = luziapi_nl_sms_segments_for_post($post);
    if ($segments > LUZIAPI_SMS_MAX_SEGMENTS) {
        luziapi_nl_log('SMS annulé (#' . $post->ID . ') : ' . $segments . ' segments (> ' . LUZIAPI_SMS_MAX_SEGMENTS . '), texte trop long.');
        return;
    }

    $create = wp_remote_post('https://api.brevo.com/v3/smsCampaigns', [
        'timeout' => 30,
        'headers' => ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => $key],
        'body'    => wp_json_encode([
            'name'       => 'SMS Article — ' . $title . ' (#' . $post->ID . ')',
            'sender'     => 'LuziApi',
            'content'    => $content,
            'recipients' => ['listIds' => [$listId]],
        ]),
    ]);
    if (is_wp_error($create)) {
        luziapi_nl_log('SMS création KO (#' . $post->ID . ') : ' . $create->get_error_message());
        return;
    }
    $code = (int) wp_remote_retrieve_response_code($create);
    $body = json_decode(wp_remote_retrieve_body($create), true);
    if ($code !== 201 || empty($body['id'])) {
        luziapi_nl_log('SMS création refusée (#' . $post->ID . ') HTTP ' . $code . ' : ' . wp_remote_retrieve_body($create));
        return;
    }
    $campaignId = (int) $body['id'];

    $send = wp_remote_post('https://api.brevo.com/v3/smsCampaigns/' . $campaignId . '/sendNow', [
        'timeout' => 30,
        'headers' => ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => $key],
    ]);
    $sendCode = is_wp_error($send) ? 0 : (int) wp_remote_retrieve_response_code($send);
    if ($sendCode !== 204) {
        luziapi_nl_log('SMS envoi refusé (#' . $post->ID . ', campagne ' . $campaignId . ') HTTP ' . $sendCode . ' : ' . (is_wp_error($send) ? $send->get_error_message() : wp_remote_retrieve_body($send)));
        return;
    }
    update_post_meta($post->ID, '_luziapi_nl_sms_sent', current_time('mysql'));
    update_post_meta($post->ID, '_luziapi_nl_sms_campaign_id', $campaignId);
    luziapi_nl_log('Campagne SMS ' . $campaignId . ' envoyée pour #' . $post->ID);
}

/* ------------------------------------------------------------------ *
 * Choix d'envoi (e-mail / SMS) — metabox sur les articles.
 * ------------------------------------------------------------------ */
add_action('add_meta_boxes', function () {
    add_meta_box('luziapi_nl_box', 'Newsletter LuziApi', 'luziapi_nl_metabox', 'post', 'side', 'default');
});

function luziapi_nl_metabox(WP_Post $post) {
    wp_nonce_field('luziapi_nl_box', 'luziapi_nl_nonce');
    $sentE     = get_post_meta($post->ID, '_luziapi_nl_sent', true);
    $sentS     = get_post_meta($post->ID, '_luziapi_nl_sms_sent', true);
    $choiceSet = get_post_meta($post->ID, '_luziapi_nl_choice_set', true) === '1';
    // Nouvel article : e-mail coché par défaut, SMS décoché.
    $email = $choiceSet ? (get_post_meta($post->ID, '_luziapi_nl_email', true) === '1') : true;
    $sms   = $choiceSet ? (get_post_meta($post->ID, '_luziapi_nl_sms', true) === '1') : false;

    echo '<p style="margin:0 0 .5em;color:#444;font-size:12px;">Envoyer cet article aux abonnés (~10 min après publication) :</p>';
    echo '<label style="display:block;margin-bottom:.4em;"><input type="checkbox" name="luziapi_nl_email" value="1" ' . checked($email, true, false) . ($sentE ? ' disabled' : '') . '> Par e-mail' . ($sentE ? ' <span style="color:#1f5e12;">✓ envoyé</span>' : '') . '</label>';
    echo '<label style="display:block;"><input type="checkbox" name="luziapi_nl_sms" value="1" ' . checked($sms, true, false) . ($sentS ? ' disabled' : '') . '> Par SMS' . ($sentS ? ' <span style="color:#1f5e12;">✓ envoyé</span>' : '') . '</label>';

    // Champ objet de l'e-mail (optionnel).
    $emailSubject = get_post_meta($post->ID, '_luziapi_nl_email_subject', true);
    $emailSubjPh  = 'Du nouveau au rucher : ' . wp_strip_all_tags(get_the_title($post));
    echo '<div style="margin-top:.7em;">';
    echo '<label for="luziapi_nl_email_subject" style="display:block;color:#444;font-size:12px;margin-bottom:.2em;">Objet de l\'e-mail (optionnel)</label>';
    echo '<input type="text" id="luziapi_nl_email_subject" name="luziapi_nl_email_subject" style="width:100%;box-sizing:border-box;"' . ($sentE ? ' disabled' : '') . ' placeholder="' . esc_attr($emailSubjPh) . '" value="' . esc_attr($emailSubject) . '">';
    echo '<p style="margin:.3em 0 0;color:#888;font-size:11px;">Vide = objet par défaut. Fais court (~50 car. avant coupure dans la boîte mail).</p>';
    echo '</div>';

    // Champ texte du SMS + compteur (le lien court est ajouté automatiquement).
    $shortlink = wp_get_shortlink($post->ID);
    if (!$shortlink) {
        $shortlink = get_permalink($post);
    }
    $reserved = strlen($shortlink) + 1 + luziapi_sms_stop_len(); // lien + espace + mention « STOP au XXXXX »
    $smsText  = get_post_meta($post->ID, '_luziapi_nl_sms_text', true);
    $smsPh    = 'LuziApi : ' . wp_strip_all_tags(get_the_title($post));

    echo '<div style="margin-top:.7em;">';
    echo '<label for="luziapi_nl_sms_text" style="display:block;color:#444;font-size:12px;margin-bottom:.2em;">Texte du SMS (optionnel)</label>';
    echo '<textarea id="luziapi_nl_sms_text" name="luziapi_nl_sms_text" rows="3" style="width:100%;box-sizing:border-box;"' . ($sentS ? ' disabled' : '') . ' placeholder="' . esc_attr($smsPh) . '">' . esc_textarea($smsText) . '</textarea>';
    echo '<p id="luziapi_sms_count" style="margin:.3em 0 0;font-size:11px;"></p>';
    $maxSeg   = (int) LUZIAPI_SMS_MAX_SEGMENTS;
    $maxLabel = $maxSeg > 1 ? ($maxSeg . ' SMS (' . $maxSeg . ' crédits/personne)') : '1 seul SMS';
    echo '<p style="margin:.3em 0 0;color:#888;font-size:11px;">Le lien court (~' . strlen($shortlink) . ' car.) et la mention légale « STOP au … » sont ajoutés automatiquement à la fin — inutile de les écrire. Vide = le titre est utilisé. Le tout doit tenir en <strong>' . esc_html($maxLabel) . '</strong> maximum, sinon l\'envoi par SMS est bloqué.</p>';
    echo '</div>';
    echo '<p style="margin:.6em 0 0;color:#888;font-size:11px;">Pour un simple envoi e-mail, laisse seulement « Par e-mail » coché.</p>';

    $js = <<<'JS'
<script>
(function(){
  var ta=document.getElementById('luziapi_nl_sms_text');
  var out=document.getElementById('luziapi_sms_count');
  if(!ta||!out){return;}
  var reserved=__RESERVED__;
  var maxSeg=__MAXSEG__;
  var smsBox=document.querySelector('input[name="luziapi_nl_sms"]');
  function norm(s){
    return s.replace(/[’‘]/g,"'").replace(/[“”„«»]/g,'"')
            .replace(/[–—]/g,'-').replace(/…/g,'...')
            .replace(/[  ]/g,' ').replace(/œ/g,'oe').replace(/Œ/g,'OE')
            .replace(/æ/g,'ae').replace(/Æ/g,'AE').replace(/·/g,'-')
            .replace(/â/g,'a').replace(/Â/g,'A').replace(/ê/g,'e').replace(/Ê/g,'E')
            .replace(/î/g,'i').replace(/Î/g,'I').replace(/ô/g,'o').replace(/Ô/g,'O')
            .replace(/û/g,'u').replace(/Û/g,'U').replace(/ë/g,'e').replace(/Ë/g,'E')
            .replace(/ï/g,'i').replace(/Ï/g,'I');
  }
  var NONGSM=/[^\x20-\x7E£¥§¿¡éèàùçìòñüöäÄÖÑÜÉ]/;
  // Éditeur classique : boutons Publier / Enregistrer (à (dé)bloquer). En
  // Gutenberg ils n'existent pas — le filet reste le blocage serveur au save.
  function pubBtns(){
    return [document.getElementById('publish'),document.getElementById('save-post')].filter(Boolean);
  }
  function setBlocked(b){
    pubBtns().forEach(function(el){el.disabled=b;el.classList.toggle('disabled',b);});
  }
  function upd(){
    var t=norm((ta.value||ta.getAttribute('placeholder')||'').trim());
    var total=t.length+reserved;
    var uni=NONGSM.test(t);
    var per=uni?70:160, perMulti=uni?67:153;
    var seg=total<=per?1:Math.ceil(total/perMulti);
    var over=seg>maxSeg;
    var smsOn=smsBox?smsBox.checked:false;
    var label='≈ '+total+' caracteres · '+(uni?'Unicode':'GSM')+' · '+seg+' SMS'+(seg>1?' ('+seg+' credits/personne)':'');
    // On ne bloque que si « Par SMS » est coché (sinon aucun SMS ne partira).
    if(over&&smsOn){
      out.textContent='⛔ '+label+' — trop long ('+maxSeg+' SMS max), raccourcis le texte';
      out.style.color='#a00';
      setBlocked(true);
    }else{
      out.textContent=label;
      out.style.color=over?'#a15c00':'#1f5e12';
      setBlocked(false);
    }
  }
  ta.addEventListener('input',upd);
  if(smsBox){smsBox.addEventListener('change',upd);}
  upd();
})();
</script>
JS;
    echo str_replace(['__RESERVED__', '__MAXSEG__'], [(int) $reserved, (int) LUZIAPI_SMS_MAX_SEGMENTS], $js);
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
    update_post_meta($post_id, '_luziapi_nl_choice_set', '1');
    update_post_meta($post_id, '_luziapi_nl_email', !empty($_POST['luziapi_nl_email']) ? '1' : '0');

    // Texte du SMS d'abord : luziapi_nl_sms_message() (ci-dessous) le relit.
    $sms_text = isset($_POST['luziapi_nl_sms_text']) ? sanitize_textarea_field(wp_unslash($_POST['luziapi_nl_sms_text'])) : '';
    update_post_meta($post_id, '_luziapi_nl_sms_text', $sms_text);

    // Blocage : on n'active « Par SMS » que si le message tient dans le plafond.
    $sms = !empty($_POST['luziapi_nl_sms']);
    if ($sms) {
        $segments = luziapi_nl_sms_segments_for_post(get_post($post_id));
        if ($segments > LUZIAPI_SMS_MAX_SEGMENTS) {
            $sms = false; // trop long : envoi SMS refusé
            set_transient('luziapi_nl_sms_blocked_' . $post_id, $segments, 60);
        }
    }
    update_post_meta($post_id, '_luziapi_nl_sms', $sms ? '1' : '0');

    $email_subject = isset($_POST['luziapi_nl_email_subject']) ? sanitize_text_field(wp_unslash($_POST['luziapi_nl_email_subject'])) : '';
    update_post_meta($post_id, '_luziapi_nl_email_subject', $email_subject);
});

/* ------------------------------------------------------------------ *
 * Avertissement dans l'éditeur quand l'envoi SMS a été bloqué (texte trop
 * long). Complète le retour en direct du compteur JS de la metabox.
 * ------------------------------------------------------------------ */
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'post') {
        return;
    }
    $post = get_post();
    if (!$post) {
        return;
    }
    $segments = get_transient('luziapi_nl_sms_blocked_' . $post->ID);
    if ($segments) {
        delete_transient('luziapi_nl_sms_blocked_' . $post->ID);
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Newsletter LuziApi :</strong> le SMS dépassait le plafond de ' . (int) LUZIAPI_SMS_MAX_SEGMENTS . ' segment(s) ('
            . (int) $segments . ' segments avec le lien et la mention « STOP au … ») — l\'envoi <em>par SMS</em> a été désactivé pour éviter un surcoût. Raccourcis le texte, puis recoche « Par SMS ».</p></div>';
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
