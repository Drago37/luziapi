# Production — luziapi.fr (o2switch)

État de la production et décisions de configuration. Complète [DEPLOIEMENT.md](../DEPLOIEMENT.md)
(comment déployer) et [AGENTS.md](../AGENTS.md) (règles de travail, méthodes de déploiement,
pièges serveur).

> Instantané rédigé à l'été 2026 : à vérifier contre le code / le site avant d'en faire un fait.

---

## Hébergement

- WordPress **mono-site** (WP 7.1, vérifié le 6 septembre 2026) chez **o2switch**, racine
  `/home/gran4488/public_html`.
- Thème **luziapi** (Timber/Twig + WooCommerce).
- HTTPS forcé : redirection 301 vers `https://www.luziapi.fr` par un bloc placé dans
  `public_html/.htaccess`, **au-dessus** de `# BEGIN WordPress`.
- **Pas de SSH ni d'accès cPanel** de notre côté → toute action serveur passe par le script à
  jeton décrit dans AGENTS.md § 4.
- Le compte FTP de déploiement est **chrooté sur le dossier du thème** : il ne voit ni
  `public_html/`, ni `wp-config.php`, ni `wp-content/plugins|mu-plugins`.

## mu-plugins

Versionnés dans [`prod-mu-plugins/`](../prod-mu-plugins), déployés par script à jeton.

| Fichier                           | Rôle                                                                                                                                                                                                                                                                                             |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `luziapi-security.php`            | En-têtes HTTP (X-Content-Type-Options, X-Frame-Options SAMEORIGIN, Referrer-Policy, Permissions-Policy, HSTS), `DISALLOW_FILE_EDIT`, pingbacks XML-RPC neutralisés (XML-RPC **pas** coupé en entier, pour préserver Jetpack), `wp_generator`/rsd/wlwmanifest retirés, message de login générique |
| `luziapi-mail-from.php`           | Expéditeur `LuziApi <no-reply@luziapi.fr>`                                                                                                                                                                                                                                                       |
| `luziapi-newsletter.php`          | Formulaire d'inscription maison + route REST Brevo                                                                                                                                                                                                                                               |
| `luziapi-newsletter-autosend.php` | Envoi e-mail + SMS à la publication d'un article                                                                                                                                                                                                                                                 |

## Boutique WooCommerce

- **4 produits**, **aucune photo mise en avant** → le thème affiche un **pot de miel dessiné en
  SVG**, coloré par variété, sur l'accueil comme sur la boutique et la fiche
  (`luziapi_jar_svg()` / `luziapi_product_jar()` dans `inc/woocommerce.php`). À remplacer par de
  vraies photos quand elles existeront.
- **Parcours d'achat mixte** : accueil = vitrine (« Voir le miel » → fiche, pas d'ajout direct) ;
  boutique = ajout AJAX (on reste sur place) ; fiche = ajout standard.
- La boutique conserve l'ordre défini des quatre miels et n'affiche plus le sélecteur de tri
  WooCommerce, retiré le 7 septembre 2026 car inutile sur ce catalogue réduit.
- Administration sous WooCommerce 10.8.1 avec **HPOS activé** (vérifié le 6 septembre 2026) :
  contournement dans `inc/woocommerce.php` du problème de clic sur les cases de la liste des
  commandes. Il couvre l'écran HPOS et l'écran historique sans retirer l'ouverture d'une commande
  par clic sur le reste de sa ligne.
- **Navigation boutique déployée le 7 septembre 2026** : bouton « Boutique » avec icône de
  magasin et panier toujours visible dans le header (« Vide », puis nombre d'articles). Le
  mini-panier s'ouvre au survol sur ordinateur et au toucher sur mobile/tablette ; son contenu est
  rafraîchi en AJAX via `woocommerce_add_to_cart_fragments`. Les accès rapides sont regroupés en
  une colonne espacée à droite, avec SOS Essaim en premier, puis Contact / S'abonner et enfin les
  réseaux sociaux.
- Pages **Panier (#8)** et **Commande (#9)** repassées en **shortcode classique**
  (`[woocommerce_cart]` / `[woocommerce_checkout]`) : en blocs Gutenberg, elles n'étaient pas
  couvertes par l'habillage du thème (images et boutons cassés). Pot SVG dans le panier via le
  filtre `woocommerce_cart_item_thumbnail`. Le récapitulatif occupe toute la largeur et le
  calculateur de frais d'expédition est masqué : les modes réellement disponibles sont déterminés
  au checkout selon la commune.
- **Fiche produit** : onglet Avis et note en étoiles retirés, stock et catégorie masqués ;
  attributs Floraison / Couleur / Texture / Goût / Brassé / Récolte / Conditionnement (pot
  plastique) / Origine / Conservation, poids 1 kg, descriptions rédigées. Depuis le 7 septembre
  2026, les quatre miels indiquent « Entre 15 et 20 °C, à l'abri de la lumière ». L'acacia affiche
  « Liquide à onctueuse » et sa description explique la cristallisation naturelle, la texture
  parfois plus ferme et l'influence possible d'une floraison tardive du colza.
- Encart d'offre « −1 €/pot dès 2 pots » sur fiche, boutique et panier.
- Le panier et le checkout rappellent que la mise au panier ne réserve pas les pots : le stock est
  réservé seulement à la validation effective de la commande.
- Les alertes de stock faible sont activées à 5 pots et les alertes de rupture à 0, à destination
  de l'adresse LuziApi.
- La documentation détaillée du parcours est dans
  [`processus-metier-commandes.md`](processus-metier-commandes.md).
- **Livraison activée**, limitée aux pays de vente — actuellement la France. Deux choix sont
  proposés au checkout : retrait au domicile de LuziApi à Luzillé sur rendez-vous pour toutes les
  commandes, et livraison gratuite sur rendez-vous uniquement à Bléré ou Luzillé. Le filtre du
  thème exige pays `FR` + code postal `37150` + ville normalisée `Bléré` ou `Luzillé` : les autres
  communes du 37150 n'obtiennent que le retrait. L'e-mail de retrait lit l'adresse centralisée
  dans les réglages WooCommerce au lieu de la dupliquer dans le workflow.
- Deux statuts métier sont enregistrés avec HPOS : **En cours de livraison** et **Prête au
  retrait**. Ils déclenchent leurs e-mails clients respectifs. Les e-mails **En attente**, **En
  cours** et **Terminée** ont également été remplacés par les formulations LuziApi validées ;
  « Terminée » ne dit plus que la commande est en chemin.
- Paiements hors ligne : « Virement bancaire ou WERO avant la remise » et « Paiement lors du
  retrait ou de la livraison — espèces ou chèque ». Le moyen « Chèque » séparé est désactivé :
  aucun chèque n'est envoyé, il est accepté uniquement au moment de la remise.
  PayPal : plugin désactivé **puis fichiers supprimés**.
- Les CGV versionnées du 8 septembre 2026 sont publiées, associées au checkout et documentées dans
  [`projet-cgv.md`](projet-cgv.md). L'adhésion CM2C est active.

### CGV et rétractation — déployées les 7 et 8 septembre 2026

- page **Conditions générales de vente (#108)** à `/conditions-generales-de-vente/`, dans le design
  du thème, et PDF immuable associé ;
- page **Exercer mon droit de rétractation (#109)** à `/retractation/` ;
- lien dans le footer français et anglais, le checkout, les e-mails et les détails de commande ;
- case d'acceptation WooCommerce obligatoire et décochée, avec le bouton
  « Commander avec obligation de paiement » ;
- version et horodatage des CGV conservés dans chaque commande ;
- copie PDF jointe au premier e-mail de confirmation client ;
- fonctionnalité `/retractation/` en deux étapes, avec accusé de réception, alerte LuziApi et trace
  privée dans la commande, sans annulation ni remboursement automatique ;
- médiateur : CM2C, adhésion valable jusqu'au 7 septembre 2029. Les documents contractuels restent
  hors du dépôt ; seules les coordonnées publiques nécessaires figurent dans les CGV.

La **révision 2 est en production depuis le 7 septembre 2026** : nouvelle information sur le
téléphone et la prospection, délai de règlement de 10 jours ouvrés avec rappel puis annulation,
e-mail d'annulation conditionné à un motif, contrôle du statut selon le mode de remise, fuseau
Europe/Paris, politique de confidentialité enrichie et champ coupon masqué sans coupon utilisable.

La **révision 3 est en production depuis le 8 septembre 2026**, sans changement juridique : le
PDF de cinq pages reprend la charte LuziApi, embarque le logo pour garantir son affichage et place
le formulaire de rétractation entier sur sa propre page. Les PDF des révisions précédentes restent
accessibles pour conserver la copie exacte acceptée par chaque client.

> **Délai de règlement porté à 10 jours ouvrés** (rappel toujours à 5) le 7 septembre 2026, sur
> toute la chaîne : traitement, e-mails, **texte public des CGV** et **PDF** `2026-09-07-v2`
> (régénéré et redéployé, l'ancien PDF « 7 jours » écrasé sur ce même millésime car aucune commande
> client ne l'avait encore accepté). La feuille d'impression du thème masque désormais aussi les
> boutons flottants (`.fab-group`) et le bandeau cookies, pour un PDF des CGV propre.

## Newsletter (Brevo)

Le plugin `mailin/sendinblue.php` est connecté et conservé pour les campagnes et les contacts,
mais **son formulaire natif `[sibwp_form]` ne fonctionne pas sur o2switch** : il poste sur l'URL
courante servie par le cache PowerBoost → réponse HTML au lieu de JSON → spinner infini. Les POST
`admin-ajax` sont en plus bloqués par le WAF anti-bot.

**Solution retenue** — mu-plugin `luziapi-newsletter.php` :

- définit `LUZIAPI_NEWSLETTER='[luziapi_newsletter]'` ;
- enregistre un shortcode rendant un **formulaire maison** (classes `.nl-form` / `.nl-consent` du
  thème) : e-mail + SMS, deux cases de consentement RGPD distinctes, honeypot
  `name="lz_extra_ref"` (nom volontairement non auto-remplissable) ;
- expose une **route REST publique** `POST /wp-json/luziapi/v1/subscribe` qui appelle l'API Brevo
  `POST /contacts` (`listIds=[2]`, `updateEnabled`, attribut `SMS` si un numéro valide est fourni).
  Le canal REST est le même que celui de Contact Form 7 : ni caché, ni bloqué.
- La saisie nationale `06 12 34 56 78` est normalisée en `+33…` côté serveur
  (`luziapi_normalize_phone()`).
- **Single opt-in.** Le double opt-in demanderait un modèle de confirmation Brevo.
- **Brevo impose un numéro SMS unique par contact** : si le numéro est déjà rattaché à un autre
  contact (erreur 400 `duplicate_parameter` / `SMS`), le handler réinscrit l'e-mail **sans** le
  SMS — pas de faux succès.

### Envoi automatique à la publication

Brevo n'a plus de campagne RSS native → mu-plugin `luziapi-newsletter-autosend.php`. À la
**première publication** d'un article (hooks `wp_after_insert_post` et `transition_post_status`
future→publish, garde-fou anti-doublon par post_meta `_luziapi_nl_sent`, **jamais** sur une
modification), il crée puis envoie une campagne e-mail Brevo (`POST /emailCampaigns` puis
`/sendNow`) à la liste, en HTML aux couleurs du site.

Personnalisation par article, via metabox. Sur un nouvel article, e-mail et SMS sont décochés par
défaut : cocher un canal constitue le choix explicite de déclencher son envoi après publication :

- **Objet de l'e-mail** — meta `_luziapi_nl_email_subject` ; vide = défaut
  « Du nouveau au rucher : {titre} » (`luziapi_nl_email_subject()`).
- **Texte du SMS** — meta `_luziapi_nl_sms_text` ; vide = titre. Compteur JS en direct
  (caractères / segments, GSM vs Unicode).

Côté SMS :

- lien court `wp_get_shortlink()` (`/?p=ID`) plutôt que le permalien ;
- message normalisé en alphabet GSM par `luziapi_sms_normalize()` (— → -, … → ..., apostrophes et
  guillemets courbes, espaces insécables…) pour limiter le nombre de segments (1 segment =
  1 crédit par personne). Plafond configurable via la constante `LUZIAPI_SMS_MAX_SEGMENTS`
  (**2** par défaut) ; au-delà, l'envoi SMS est bloqué (compteur metabox + save_post + garde-fou
  avant Brevo) ;
- mention légale **« STOP au [STOP_CODE] »** ajoutée d'office (conformité France, constante
  `LUZIAPI_SMS_STOP`) ; Brevo remplace `[STOP_CODE]` par le numéro court réel à l'envoi ;
- **contrainte horaire Brevo** : SMS marketing uniquement 8h–21h30, jamais le dimanche ni les
  jours fériés — sinon mis en file jusqu'au prochain créneau autorisé. Donc **publier en journée**.

Compte Brevo : liste id **2** (« LuziApi Newsletter »), offre gratuite 300 mails/jour, crédits SMS
achetés (le champ SMS est donc actif).

## E-mails

- Envoi **natif `mail()`** : o2switch signe en DKIM, les messages arrivent en boîte de réception
  Gmail. Le SMTP authentifié `mail.luziapi.fr:465` **ne marche pas** (voir AGENTS.md § 5).
- Les sept e-mails transactionnels de statut de commande utilisent depuis le **8 septembre 2026**
  le gabarit LuziApi complet : logo et charte espresso/miel, récapitulatif WooCommerce conservé,
  coordonnées et informations légales, médiation CM2C, réseaux sociaux et rappel discret
  d'inscription aux actualités par e-mail et/ou SMS. Le bouton « Suivre ma commande » n'est pas
  affiché tant que le suivi invité prévu en phase 2 n'existe pas. Les fichiers ont été vérifiés
  par empreinte après déploiement et l'OPcache a été réinitialisé. Le test E2E en production du
  8 septembre 2026 a réellement expédié la série d'e-mails et validé **32/32 assertions** ; ses
  cinq commandes et son produit temporaires ont été supprimés.
- Les notifications internes « Nouvelle commande », « Commande annulée » et « Paiement échoué »
  utilisent également depuis le 8 septembre 2026 un gabarit LuziApi compact : récapitulatif,
  mode de remise, paiement, note client, coordonnées et bouton d'accès direct à la commande. Elles
  n'affichent volontairement ni newsletter ni mentions légales destinées au client. Le test E2E
  réel a validé les gabarits « Nouvelle commande » et « Commande annulée ».
- Les quatre e-mails client WooCommerce encore natifs — paiement échoué, remboursement, note au
  client et détails/demande de paiement — utilisent depuis le 8 septembre 2026 le même gabarit
  LuziApi complet, en HTML et texte brut. La demande de paiement reste une action strictement
  manuelle, utile notamment pour une commande prise par téléphone. Chaque e-mail client lié à une
  commande ajoute désormais une note privée avec son objet et le résultat du transport, sans
  recopier le destinataire. « Transmis au service de messagerie » confirme la remise à `mail()`,
  pas la réception finale. Les empreintes des huit fichiers déployés correspondent au dépôt,
  l'OPcache a été vidé et le test E2E production sans envoi valide **37/37 assertions**.
- Expéditeur : `LuziApi <no-reply@luziapi.fr>` (mu-plugin `luziapi-mail-from.php`).
- `activate_email=no` côté plugin Brevo → les mails transactionnels du site restent natifs ; Brevo
  ne sert qu'aux campagnes.
- **Domaine authentifié dans Brevo** : DKIM CNAME `brevo1` / `brevo2._domainkey`, TXT
  `brevo-code`, DMARC **unique** `_dmarc` = `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com`.
  ⚠️ Un second enregistrement DMARC invaliderait l'ensemble.
- Expéditeur Brevo = `no-reply@luziapi.fr` (sender id 2, SPF/DKIM OK). L'ancien sender Gmail
  (id 1) est conservé mais inutilisé. `sib_home_option` : from = no-reply, sender = 2.
- `admin_email`, destinataire Contact Form 7 (#21) et notifications WooCommerce :
  `luziapi37150@gmail.com`. La notification WooCommerce **« Nouvelle commande »** est
  explicitement activée avec cette adresse comme destinataire (vérifié le 6 septembre 2026).
  WooCommerce from = `no-reply@luziapi.fr`. CF7 : sender = no-reply, Reply-To = `[your-email]`.

## Page « Récupération d'essaims »

Page WP `recuperation-essaims` (id 84). **Contenu riche stocké en base**, pas dans le dépôt :
bouton d'appel rouge `.btn-sos`, encadré d'avertissement `.swarm-warn`, `<div id="essaim-map">`.

Leaflet est chargé sur cette page (condition `is_page('recuperation-essaims')` dans
`inc/setup.php`) et `assets/js/main.js` y dessine un **cercle rouge de 15 km**
(`LUZIAPI_MAP.radius=15000`) centré sur le domicile. SEO local : JSON-LD `LocalBusiness` enrichi
(`GeoCircle` 15 km + `makesOffer` service essaims) dans `inc/seo.php`. Le bouton flottant
`.fab-sos` (`sticky-actions.twig`) et le lien « En savoir plus » de l'encart d'accueil (`#essaims`)
pointent vers cette page.

## Multilingue — page `/en/`

Les plugins de traduction qui interceptent le rendu **plantent** avec ce thème (voir AGENTS.md
§ 5). À la place, une **page dédiée** :

- page WP slug `en` (id 89), gabarit `templates/page-en.twig` routé dans `page.php` ;
- miels via `luziapi_get_honeys_en()` dans `inc/shop.php` : noms et descriptions EN mappés par
  slug, prix et disponibilité dynamiques depuis WooCommerce, attribut Récolte traduit ;
- bouton de langue flottant « 🇬🇧 EN » (et retour « 🇫🇷 FR » sur `/en/`) dans
  `sticky-actions.twig` ;
- **formulaire de contact EN** = un second formulaire CF7 (« Contact (English) », id **91**,
  dupliqué du FR #21, libellés traduits). Son id est stocké dans l'option `luziapi_cf7_en_id` et
  `page.php` l'injecte sur `/en/`. Le thème force sa locale à `en_GB`, y compris lors des requêtes
  REST, afin que ses messages système et de validation restent en anglais ;
- Contenus rédigés à la première personne (I / my), comme l'accueil FR.
- Le gabarit, son SEO, le header, le mini-panier, les boutons flottants et le footer sont traduits
  sur `/en/`. Les liens vers une ressource uniquement française l'indiquent explicitement.
- **Pas de partie essaims en anglais** (réservée aux locaux). L'achat en ligne, la newsletter,
  les documents et les pages juridiques demeurent en français.
- Les informations métier reflètent les règles de la boutique : retrait au domicile à Luzillé
  sur rendez-vous, livraison gratuite uniquement à Luzillé ou Bléré sur rendez-vous, et
  conservation du miel entre 15 et 20 °C à l'abri de la lumière.
- L'accueil et `/en/` publient les liens alternatifs `hreflang` français, anglais et `x-default`.

## Bandeau cookies (CookieAdmin / cookieadmin-pro)

Les libellés des boutons **ne se configurent pas** via l'option `cookieadmin_consent_settings` :
le rendu ignore `cookieadmin_gdpr.*_btn`. Ils sont donc forcés **par JS** dans
`assets/js/main.js` (IDs `cookieadmin_accept_button` / `reject` / `customize`) : en français sur
le site principal et en anglais sur `/en/`. Le crédit « Propulsé par » est masqué en CSS
(`.cookieadmin-poweredby{display:none}`) pour le contraste.

## Accessibilité et performances

- Couleurs de texte `--honey-deep` / `--muted` assombries (`#8a5410` / `#7d6038`) pour le
  contraste AA ; bouton Facebook en `#0866ff` (bleu officiel, conforme).
- Leaflet n'est plus enqueue : `assets/js/main.js` le charge en lazy (IntersectionObserver,
  `rootMargin` 300 px) via `loadLeaflet()` à l'approche de `#map` / `#essaim-map`.
- PageSpeed : ~75 mobile, 99 bureau.

## Connexion à l’administration

- L’écran WordPress natif `/wp-login.php` conserve tous ses parcours (connexion, mot de passe
  oublié, choix de langue), avec une identité visuelle LuziApi chargée par `inc/login.php` et
  `assets/css/login.css` : logo, palette miel et bois, motif alvéolé et mise en page responsive.

## Sauvegardes

- **UpdraftPlus** installé et activé (par script à jeton), planifié : base **quotidienne**,
  fichiers **hebdomadaires**, rétention **7**.
- ⚠️ **Stockage distant non connecté** — c'est à l'utilisateur de lier son Google Drive (OAuth,
  Réglages → UpdraftPlus) et de lancer la première sauvegarde.
- Vérification du 6 septembre 2026 : **aucune archive de base UpdraftPlus n'est encore présente**
  sur le serveur. La planification seule ne constitue donc pas encore une sauvegarde récupérable ;
  lancer et contrôler la première sauvegarde reste nécessaire.
- Côté hébergeur : sauvegardes automatiques o2switch (**JetBackup**) dans le cPanel, à vérifier
  par l'utilisateur.

## SEO

- **Search Console** : propriété `https://www.luziapi.fr` vérifiée (balise meta
  `google-site-verification` émise inconditionnellement dans `inc/setup.php`).
- Sitemap natif `wp-sitemap.xml` soumis, nettoyé par des filtres dans `inc/seo.php` : panier,
  commande et mon-compte exclus, sitemap des auteurs désactivé.
- Page `sample-page` mise à la corbeille.

## Jetpack

Modules de tracking désactivés volontairement (RGPD) : `stats`, `woocommerce-analytics`, `blaze`,
`subscriptions`. Modules conservés : protect, account-protection, blocks, contact-form, json-api,
verification-tools, notes. Pour les statistiques, utiliser Google Search Console.

---

**Aucun mot de passe ni jeton n'est stocké dans ce dépôt** : les accès vivent dans `.env.local`.
