<?php
/**
 * Plugin Name: LuziApi — expéditeur des e-mails
 * Description: Envoi NATIF (mail() o2switch, signé DKIM). Expéditeur no-reply@luziapi.fr, nom LuziApi.
 */
add_filter('wp_mail_from', function(){ return 'no-reply@luziapi.fr'; });
add_filter('wp_mail_from_name', function(){ return 'LuziApi'; });
