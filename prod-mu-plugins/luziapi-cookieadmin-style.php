<?php
/**
 * Plugin Name: LuziApi — habillage CookieAdmin
 * Description: Aligne la bannière/ modale CookieAdmin sur la charte du thème LuziApi (miel & bois).
 */
add_action('wp_head', function () {
    $css = <<<'CSS'
/* Bannière */
.cookieadmin_law_container{background:#fffaf0!important;color:#3a2917!important;border-top:1px solid #e6d2a8!important;box-shadow:0 -6px 26px rgba(43,29,16,.13)!important}
.cookieadmin_law_container,.cookieadmin_cookie_modal,.cookieadmin_preference_details,.cookieadmin_btn{font-family:'Hanken Grotesk',system-ui,-apple-system,sans-serif!important}
#cookieadmin_notice_title,.cookieadmin_preference_title,.cookieadmin_mod_head,.stitle{font-family:'Fraunces',Georgia,serif!important;color:#2b1d10!important}
#cookieadmin_notice,.cookieadmin_notice_con{color:#3a2917!important}
.cookieadmin_desc{color:#866a48!important}
/* Boutons */
.cookieadmin_btn{font-weight:600!important;border-radius:10px!important;border:1px solid transparent!important;transition:background .15s,color .15s!important}
.cookieadmin_accept_btn,#cookieadmin_accept_button,#cookieadmin_accept_modal_button,.cookieadmin_save_btn,#cookieadmin_prf_modal_button{background:#e0a124!important;color:#2b1d10!important;border-color:#c07c14!important}
.cookieadmin_accept_btn:hover,#cookieadmin_accept_button:hover,#cookieadmin_accept_modal_button:hover,.cookieadmin_save_btn:hover,#cookieadmin_prf_modal_button:hover{background:#c07c14!important;color:#fffaf0!important}
.cookieadmin_reject_btn,#cookieadmin_reject_button,#cookieadmin_reject_modal_button,.cookieadmin_customize_btn,#cookieadmin_customize_button{background:#f5e6c4!important;color:#432c16!important;border-color:#e6d2a8!important}
.cookieadmin_reject_btn:hover,.cookieadmin_customize_btn:hover,#cookieadmin_reject_button:hover,#cookieadmin_customize_button:hover{background:#e6d2a8!important}
/* Liens & toggles (override du bleu par défaut) */
.cookieadmin_remark,.cookieadmin_showmore,.cookieadmin_modal_footer_links a{color:#c07c14!important}
.cookieadmin_close_pref{color:#3a2917!important}
html body input:checked+.cookieadmin_slider,html body input:disabled+.cookieadmin_slider{background-color:#e0a124!important}
/* "Propulsé par" discret */
.cookieadmin-poweredby{opacity:.5!important}
CSS;
    echo "\n<style id=\"luziapi-cookieadmin\">" . $css . "</style>\n";
}, 1000);