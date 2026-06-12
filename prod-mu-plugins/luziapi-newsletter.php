<?php
/**
 * Plugin Name: LuziApi — Newsletter (form maquette + REST -> Brevo)
 * Description: Formulaire newsletter maison (design maquette, e-mail + SMS) soumis via /wp-json/luziapi/v1/subscribe vers l'API Brevo. Contourne le formulaire du plugin (incompatible avec le cache PowerBoost + WAF o2switch).
 */

if (!defined('LUZIAPI_NEWSLETTER')) {
    define('LUZIAPI_NEWSLETTER', '[luziapi_newsletter]');
}
if (!defined('LUZIAPI_BREVO_LIST_ID')) {
    define('LUZIAPI_BREVO_LIST_ID', 2);
}

/* 1) Formulaire (design maquette : reprend les classes .nl-form / .nl-consent du theme) */
add_shortcode('luziapi_newsletter', function () {
    $endpoint = esc_url_raw(rest_url('luziapi/v1/subscribe'));
    $nonce    = wp_create_nonce('wp_rest');
    ob_start();
    ?>
    <form class="nl-form" id="luziapi-nl-form" novalidate>
        <div class="nl-msg" id="luziapi-nl-msg" role="status" hidden></div>
        <input type="email" name="email" id="luziapi-nl-email" placeholder="votre@email.fr" aria-label="Votre adresse e-mail" required>
        <input type="tel" name="sms" id="luziapi-nl-sms" placeholder="06 12 34 56 78 (facultatif, pour les SMS)" aria-label="Votre numéro de mobile">
        <label class="nl-consent">
            <input type="checkbox" name="consent" id="luziapi-nl-consent" required>
            <span>J'accepte de recevoir les actualités de LuziApi par <b>e-mail</b> (RGPD).</span>
        </label>
        <label class="nl-consent">
            <input type="checkbox" name="sms_consent" id="luziapi-nl-sms-consent">
            <span>J'accepte aussi d'être prévenu par <b>SMS</b> (facultatif). «&nbsp;STOP&nbsp;» pour me désinscrire.</span>
        </label>
        <input type="text" name="lz_extra_ref" id="luziapi-nl-hp" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
        <button type="submit" id="luziapi-nl-btn">Je m'inscris</button>
    </form>
    <style>
    #luziapi-nl-form .nl-msg{border-radius:14px;padding:.7em 1.1em;font-size:.9rem;margin-bottom:4px;text-align:left;line-height:1.4}
    #luziapi-nl-form .nl-msg--ok{background:#eaf7e6;color:#1f5e12}
    #luziapi-nl-form .nl-msg--err{background:#fdecea;color:#7a1c12}
    #luziapi-nl-form button[disabled]{opacity:.7;cursor:default}
    </style>
    <script>
    (function(){
        var f=document.getElementById('luziapi-nl-form'); if(!f) return;
        var msg=document.getElementById('luziapi-nl-msg'),
            btn=document.getElementById('luziapi-nl-btn');
        function show(t,ok){ msg.hidden=false; msg.textContent=t; msg.className='nl-msg '+(ok?'nl-msg--ok':'nl-msg--err'); }
        f.addEventListener('submit',function(e){
            e.preventDefault();
            var email=document.getElementById('luziapi-nl-email').value.trim();
            var sms=document.getElementById('luziapi-nl-sms').value.trim();
            var consent=document.getElementById('luziapi-nl-consent').checked;
            var smsConsent=document.getElementById('luziapi-nl-sms-consent').checked;
            var hp=document.getElementById('luziapi-nl-hp').value;
            if(!email){ show("Merci d'indiquer votre adresse e-mail.",false); return; }
            if(!consent){ show("Merci de cocher la case de consentement e-mail.",false); return; }
            if(sms && !smsConsent){ show("Pour recevoir les SMS, cochez la case de consentement SMS (ou laissez le téléphone vide).",false); return; }
            var old=btn.textContent; btn.disabled=true; btn.textContent='Envoi…';
            fetch(<?php echo wp_json_encode($endpoint); ?>,{
                method:'POST',
                headers:{'Content-Type':'application/json','X-WP-Nonce':<?php echo wp_json_encode($nonce); ?>},
                body:JSON.stringify({email:email,sms:sms,consent:consent,sms_consent:smsConsent,website:hp})
            }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
              .then(function(res){
                  btn.disabled=false; btn.textContent=old;
                  if(res.ok){ show((res.d&&res.d.message)||'Merci ! Vous êtes bien inscrit.',true); f.reset(); }
                  else { show((res.d&&res.d.message)||'Une erreur est survenue, réessayez.',false); }
              }).catch(function(){ show('Une erreur réseau est survenue, réessayez.',false); btn.disabled=false; btn.textContent=old; });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

/* 2) Endpoint REST public : email (+ SMS optionnel) -> API Brevo (liste configuree) */
add_action('rest_api_init', function () {
    register_rest_route('luziapi/v1', '/subscribe', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'luziapi_newsletter_subscribe',
    ]);
});

/**
 * Normalise un numero de telephone saisi en clair vers le format international (+33...).
 * Tolere : "06 12 34 56 78", "06.12.34.56.78", "0033 6...", "33 6...", "+33 6...".
 * Hypothese FR par defaut (indicatif +33) si aucun indicatif fourni.
 */
function luziapi_normalize_phone($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    $hasPlus = (strpos($raw, '+') === 0);
    $digits  = preg_replace('/\D/', '', $raw); // chiffres uniquement
    if ($digits === '') {
        return '';
    }
    if ($hasPlus) {
        return '+' . $digits;               // deja international
    }
    if (strpos($digits, '0033') === 0) {
        return '+33' . substr($digits, 4);  // 0033 6... -> +33 6...
    }
    if (strpos($digits, '0') === 0) {
        return '+33' . substr($digits, 1);  // 06... -> +33 6... (national FR)
    }
    if (strpos($digits, '33') === 0 && strlen($digits) >= 11) {
        return '+' . $digits;               // 33 6... -> +33 6...
    }
    return '+33' . $digits;                 // chiffres bruts : on suppose FR
}

function luziapi_newsletter_subscribe(WP_REST_Request $req) {
    $email      = sanitize_email((string) $req->get_param('email'));
    $consent    = (bool) $req->get_param('consent');
    $smsConsent = (bool) $req->get_param('sms_consent');
    // Honeypot : vérifié via la clé JS (website) ET le nom réel du champ (lz_extra_ref),
    // pour attraper aussi les bots qui appellent l'API directement, sans passer par le JS.
    $hp         = trim((string) $req->get_param('website')) . trim((string) $req->get_param('lz_extra_ref'));
    // Numero : on tolere les saisies habituelles (06 12 34 56 78, 06.12..., 0033...) et
    // on normalise vers le format international attendu par Brevo (+33...).
    $sms = luziapi_normalize_phone((string) $req->get_param('sms'));

    if ($hp !== '') { // honeypot rempli = bot : on fait semblant d'accepter
        return new WP_REST_Response(['status' => 'ok', 'message' => 'Merci !'], 200);
    }
    if (!is_email($email)) {
        return new WP_REST_Response(['status' => 'invalid', 'message' => 'Adresse e-mail invalide.'], 400);
    }
    if (!$consent) {
        return new WP_REST_Response(['status' => 'consent', 'message' => 'Merci de cocher la case de consentement e-mail.'], 400);
    }

    $attributes = [];
    if ($sms !== '') {
        if (!$smsConsent) {
            return new WP_REST_Response(['status' => 'sms_consent', 'message' => 'Pour les SMS, cochez la case de consentement SMS (ou laissez le téléphone vide).'], 400);
        }
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $sms)) {
            return new WP_REST_Response(['status' => 'sms_invalid', 'message' => 'Numéro de mobile invalide. Indiquez un mobile, ex. 06 12 34 56 78.'], 400);
        }
        $attributes['SMS'] = $sms;
    }

    $key = get_option('sib_api_key_v3');
    if (!$key) {
        return new WP_REST_Response(['status' => 'config', 'message' => 'Service indisponible.'], 500);
    }

    $payload = [
        'email'         => $email,
        'listIds'       => [(int) LUZIAPI_BREVO_LIST_ID],
        'updateEnabled' => true,
    ];
    if (!empty($attributes)) {
        $payload['attributes'] = $attributes;
    }

    $resp = wp_remote_post('https://api.brevo.com/v3/contacts', [
        'timeout' => 20,
        'headers' => [
            'accept'       => 'application/json',
            'content-type' => 'application/json',
            'api-key'      => $key,
        ],
        'body' => wp_json_encode($payload),
    ]);
    if (is_wp_error($resp)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'Une erreur est survenue, réessayez.'], 502);
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code === 201 || $code === 204) {
        return new WP_REST_Response(['status' => 'success', 'message' => 'Merci ! Vous êtes bien inscrit à la newsletter LuziApi.'], 200);
    }

    $body   = json_decode(wp_remote_retrieve_body($resp), true);
    $dupIds = isset($body['metadata']['duplicate_identifiers']) ? (array) $body['metadata']['duplicate_identifiers'] : [];

    if ($code === 400 && isset($body['code']) && $body['code'] === 'duplicate_parameter') {
        // L'e-mail existe deja (avec updateEnabled c'est rare, mais on couvre le cas).
        if (in_array('EMAIL', $dupIds, true)) {
            return new WP_REST_Response(['status' => 'success', 'message' => 'Vous êtes déjà inscrit — merci !'], 200);
        }
        // Le numero est deja rattache a un autre contact : on inscrit l'e-mail sans reassigner le SMS.
        if (in_array('SMS', $dupIds, true)) {
            unset($payload['attributes']);
            $resp2 = wp_remote_post('https://api.brevo.com/v3/contacts', [
                'timeout' => 20,
                'headers' => ['accept' => 'application/json', 'content-type' => 'application/json', 'api-key' => $key],
                'body'    => wp_json_encode($payload),
            ]);
            $code2 = is_wp_error($resp2) ? 0 : (int) wp_remote_retrieve_response_code($resp2);
            if ($code2 === 201 || $code2 === 204) {
                return new WP_REST_Response(['status' => 'success', 'message' => 'Merci ! Vous êtes bien inscrit à la newsletter LuziApi.'], 200);
            }
        }
    }
    return new WP_REST_Response(['status' => 'error', 'message' => 'Une erreur est survenue, réessayez.'], 502);
}
