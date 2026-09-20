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
- **Gestion des commandes enrichie depuis le 8 septembre 2026** : la fiche HPOS affiche une
  **Source commande** distincte de l'attribution marketing, permet de choisir ou corriger le mode
  de remise avant l'étape de livraison/retrait et propose une option par commande pour bloquer
  tous les e-mails WooCommerce, client comme administrateur, sans interrompre les statuts ni le
  stock. Les notes privées et les notes client sont explicitement distinguées et une confirmation
  précède tout e-mail de note au client. La date et l'heure de création sont présentées en
  `JJ/MM/AAAA` et `HH:MM`, tout en conservant les valeurs techniques attendues par WooCommerce.
- Les sources proposées sont Boutique en ligne, Téléphone, Marché / événement, E-mail /
  formulaire, Réseaux sociaux et Autre. La boutique renseigne automatiquement sa source ; une
  commande saisie dans l'administration reçoit l'attribution native « Administration web » si
  WooCommerce n'en possède aucune. L'absence de donnée marketing est libellée « Attribution
  marketing indisponible » plutôt que « Inconnue ». La collecte d'attribution au checkout est
  conditionnée au consentement global ou Marketing de CookieAdmin.
- **Répertoire clients déployé le 8 septembre 2026** sous WooCommerce : il lit directement les
  commandes HPOS et retrouve aussi les clients invités connus uniquement par téléphone, sans
  dépendre de l'indexation analytique native. Les commandes sont regroupées par e-mail ou, à
  défaut, par numéro normalisé. Un historique sans e-mail rejoint ensuite l'unique adresse connue
  avec le même numéro ; aucune fusion n'est faite si plusieurs adresses partagent ce téléphone.
  Le répertoire affiche les coordonnées cliquables, la dernière commande, le nombre de commandes,
  le total et les sources, avec recherche. Il ne crée aucun compte et n'inscrit personne aux
  communications marketing. Empreintes vérifiées, OPcache vidé et présence d'un client sans
  e-mail confirmée en production sans exposer ses données.
- **Tableau de pilotage déployé le 9 septembre 2026**, placé en premier sous WooCommerce. Son
  premier lot, en lecture seule, fournit une vue annuelle des commandes validées (totaux, volume,
  panier moyen, évolution mensuelle avec Chart.js local, statuts à traiter et sources) ainsi
  qu'une nouvelle vue Clients regroupant prudemment l'historique par e-mail ou téléphone. Le
  module PHP est le premier code métier du thème sous `src/`, en architecture hexagonale et DDD
  pragmatique, chargé par l'autoload PSR-4 de Composer. L'onglet **Déclaration fiscale** rappelle
  le régime micro-BA (recettes brutes, moyenne triennale, abattement de 87 %, minimum de 305 €),
  mais reste volontairement préparatoire : le montant fiscal ne sera calculé qu'à partir du futur
  registre chronologique des encaissements. Les montants actuels de commandes ne sont présentés
  que comme repères commerciaux. Les fichiers ont été vérifiés après transfert, WordPress charge
  bien le module et l'OPcache a été vidé.
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
- Le lot d'administration des commandes déployé le 8 septembre 2026 a été vérifié par empreintes
  SHA-256 et vidage d'OPcache. Le test E2E complet de production, sans expédition réelle, valide
  **46/46 assertions**, dont la saisie d'une commande Téléphone, l'ajout du retrait, son traitement
  jusqu'à Terminée, le mouvement de stock unique et le blocage de tous ses e-mails. Les six
  commandes et le produit temporaires ont été supprimés automatiquement.
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

## Déploiement du thème — incident du 10 septembre 2026

Le tableau de pilotage (`src/Pilotage/`) est désormais **intégralement déployé** (109 fichiers),
avec la **Vente** unifiée : point d'entrée unique de création de commande (l'écran natif WooCommerce
de création est redirigé, l'édition reste possible), préremplissage client depuis le répertoire et
listes déroulantes en autocomplete.

**Ce qui s'est passé.** Un `make deploy` lancé la veille (9 septembre) a été **coupé en plein
transfert** (crédits de l'assistant épuisés). `make deploy` mirror **tout le thème** ; interrompu,
il a laissé `src/Pilotage/` à **27 fichiers sur 109**. Or `functions.php` appelle
`PilotageServiceProvider::boot()`, qui instancie des classes parmi les 82 manquantes → **fatal PHP
sur toute page chargeant le thème**. Le site est resté **en 500 environ 24 h**, masqué en façade
par le **cache PowerBoost** qui servait encore la home en 200.

**Diagnostic (à refaire tel quel).**

- Tester une **URL non cachée** :
  `curl -sL -o /dev/null -w '%{http_code}' 'https://www.luziapi.fr/wp-login.php?x='$RANDOM` (200
  attendu). Ne **jamais** se fier à la home sans cache-buster.
- Comparer le nombre de fichiers : `find src/ | wc -l` en prod (FTPS) vs `git ls-files 'www/.../src/**'`.

**Réparation appliquée.**

1. `mirror -R --no-perms` (sans `--delete`) du dossier complet `src/Pilotage/` → 109/109 (recrée les
   dossiers manquants) ;
2. envoi des fichiers runtime restants (CSS / JS / Twig / `inc/woocommerce.php`) ;
3. `opcache_reset()` via script à jeton (déposé, appelé, supprimé) ;
4. **vérification SHA-256** local ↔ prod de tous les fichiers déployés (identiques) + santé HTTP sur
   URL non cachées (`/`, `/wp-login.php`, `/boutique/`, `/mon-compte/`, `/panier/` → 200).

**Leçon.** `make deploy` (mirror de tout le thème) est **fragile à l'interruption** : une coupure
laisse prod à moitié déployé, donc en 500. Préférer le **FTPS ciblé** des seuls fichiers modifiés
(voir `AGENTS.md` § 3), avec **vérification SHA-256** et un **contrôle post-déploiement** sur une URL
non cachée.

## Recettes — encaissement automatique (10 septembre 2026)

- **Règle métier :** une commande **« Terminée »** vaut encaissement. Sa recette est portée au
  registre **automatiquement** (`WooCommerceReceiptSubscriber`), sans rapprochement manuel. Les
  commandes issues de la Vente gèrent déjà la leur (exclues du hook). Le rapprochement assisté reste
  disponible pour les cas particuliers.
- **Bug corrigé au passage :** la colonne `sequence_number` de `luziapi_receipts` était `NOT NULL`
  (un vieux schéma que `dbDelta` n'a jamais rendu nullable), alors que le dépôt insère NULL puis la
  renseigne → **tout enregistrement de recette échouait**, d'où un tableau à 0 €. Migration de schéma
  **v8** (`ALTER … MODIFY sequence_number … NULL`), appliquée en prod le 10 septembre 2026.
- **État :** l'historique a été rattrapé (4 commandes terminées, **97 €** au registre) via le
  rapprochement assisté. Le futur est automatique.

## Recettes orphelines de commandes supprimées (12 septembre 2026)

- **Constat :** l'encaissé 2026 affichait **404 €** alors que les « Commandes validées » valaient
  **272 €**. Le delta de **132 €** venait de **5 recettes orphelines** rattachées à des commandes
  **supprimées** (174, 177, 294 = encaissement auto ; 301, 302 = Vente — vraisemblablement des
  commandes de test supprimées). Supprimer/corbeiller une commande ne retirait pas sa recette, d'où
  de l'« argent fantôme » au registre.
- **Nettoyage ponctuel :** suppression ciblée des 5 lignes (script à jeton `_cleanup-receipts.php`,
  défensif : ne supprime que si la commande est réellement absente) → registre 2026 ramené à
  **272 €**.
- **Correctif systémique :** abonné `WooCommerceOrphanReceiptSubscriber`
  (`woocommerce_trash_order` + `woocommerce_before_delete_order`) qui **supprime** les recettes d'une
  commande dès sa mise à la corbeille ou sa suppression (pas de contre-passe : la commande n'existe
  plus). Idempotent. Tests `make e2e-orphan-receipt-local` / `e2e-orphan-receipt-prod`.

## Suivi de commande sans compte — mise en ligne (10 septembre 2026)

- **En ligne :** page `/suivi-commande/` publiée (slug `suivi-commande`, gabarit appliqué par le
  slug). Deux accès invités : numéro + e-mail de facturation, ou lien magique. Rendu vérifié en prod :
  `200`, **`noindex` + `no-store`** même sur l'URL nue (PowerBoost ne la met pas en cache), aucun
  e-mail dans l'URL. Le bouton « Suivre ma commande » apparaît désormais dans les e-mails client
  (conditionné à l'existence de la page).
- **Tables :** `wp_luziapi_tracking_grants`, `_sessions`, `_rate_limits`, `_status_events`, créées
  automatiquement par la migration sur `init`.
- **Créer la page** (fait manuellement, écriture prod bloquée par le garde-fou de l'assistant) :
  WP admin → Pages → Ajouter, slug **`suivi-commande`**, Publier.

### Rechute vendor lors du déploiement (même racine que l'incident du 9)

Le déploiement de ce lot a **remis prod en 500** quelques minutes : en ajoutant Monolog, l'autoload
régénéré s'est mis à exiger `react/promise` (une dépendance **prod**), **absente du vendor de prod**
laissé incomplet par le déploiement interrompu du 9 septembre. Le **contrôle post-déploiement l'a
détecté immédiatement**. **Correctif :** `composer install --no-dev -o` (vendor prod-only, 517
fichiers) puis **mirror du vendor complet** → toutes les dépendances présentes → site rétabli.

**Leçon :** quand une dépendance Composer change, déployer le **vendor entier** régénéré, jamais le
seul paquet + autoload (voir `AGENTS.md` § 3). Le vendor de prod est désormais complet et cohérent.

## Programme de fidélité — mise en ligne (10 septembre 2026)

- **En ligne :** lots 1 à 4 du programme « 10 pots achetés, le 11e offert » (module `src/Loyalty/`,
  voir [fidelite.md](fidelite.md)). Déploiement **FTPS ciblé** de 56 fichiers thème (aucune nouvelle
  dépendance Composer : les classes `LuziApi\Loyalty\…` se chargent en PSR-4, l'autoload prod n'est
  pas `-a`). OPcache vidé, empreintes SHA-256 vérifiées, smoke test `/suivi-commande`, `/wp-login.php`
  et home en **200 sans erreur**.
- **Table :** `wp_luziapi_loyalty_ledger` créée automatiquement par la migration sur `init`
  (schéma v1). Journal append-only.
- **⚠ Action requise pour activer le comptage :** aucun pot n'est crédité tant que les produits ne
  portent pas la méta **`_luziapi_pot_admissible = yes`** (case « Pot admissible à la fidélité » dans
  la fiche produit → « Options LuziApi »). **Cocher les pots de miel en catalogue**, sinon le
  programme reste dormant. À faire manuellement (ou backfill de la méta).
- **Rétro-crédit du passé — fait le 10 septembre 2026 :** une fois les pots cochés admissibles, le
  backfill (`tools/backfill-loyalty.php`) a crédité **9 pots sur 4 commandes** déjà « Terminée ».
  Idempotent (clé `credit:{orderId}`, daté à la complétion) : rejeu vérifié `credited=0, already=4`.
  Exécuté par script à jeton temporaire (uploadé, appelé en HTTPS, supprimé ; tool `tools/` non
  déployé en temps normal). Rejouable après avoir coché de nouveaux produits.
- **Deux choses distinctes à ne pas confondre** (voir `AGENTS.md` § 5) : le **« 1 €/pot »** est la
  remise de volume au panier (`inc/shop.php`, inchangée) ; la **fidélité** est le nouveau programme
  de pots. La **remise remerciement** (lot 3) est encore autre chose (geste monétaire libre).
- **Page « Fidélité » du pilotage — déployée le 10 septembre 2026 :** onglet dédié dans le tableau
  de bord (récap par client + classements meilleurs clients / plus profité / plus de remises).
  Déploiement FTPS ciblé (28 fichiers, dont l'onglet ajouté aux 8 pages existantes), empreintes et
  smoke test vérifiés, OPcache vidé. Lecture seule, aucune écriture. Passée ensuite en **affichage
  par année** (sélecteur, comme les Recettes).
- **Évolutions fidélité — déployées le 10 septembre 2026 :** seuil porté à **15** (le 16e offert),
  moteur **en réconciliation** (remboursements partiels gérés),
  **ajustement manuel** des pots sur la fiche, et **explications client** (e-mail de confirmation,
  boutique / fiche produit / panier, accueil, page de suivi avant connexion). Déploiement FTPS
  ciblé + suppression des anciennes commandes du serveur ; OPcache vidé, empreintes et smoke test OK.
  Détails dans [fidelite.md](fidelite.md).
- **Piège de déploiement constaté :** pour lister les fichiers à pousser, le pathspec d'exclusion
  git doit être en **chemin complet** (`':(exclude)www/wp-content/themes/luziapi/tools/**'`) — un
  glob `*/tools/*` combiné à un pathspec absolu **n'exclut pas** et a poussé des outils de test en
  prod (retirés aussitôt). Toujours vérifier qu'aucun `tools/` ne part, et que `tools/` reste absent
  du serveur après coup.

---

## Fuseau horaire du site — corrigé le 11 septembre 2026

**Symptôme :** impossible d'enregistrer une récolte (« Stocks et lots » du pilotage) — message
générique « L'opération n'a pas été enregistrée… ». Aucun lot n'avait jamais pu être créé
(`wp_luziapi_harvest_lots` à 0 ligne), alors que les mouvements de stock issus des commandes
passaient.

**Cause racine :** le réglage WordPress du fuseau était **vide** (`timezone_string=''`,
`gmt_offset=0`) → le site tournait en **UTC** au lieu d'**Europe/Paris**. Les valeurs par défaut du
formulaire (`date de récolte` = aujourd'hui, `mise en pots` = maintenant) étaient calculées en UTC,
donc affichées avec ~2 h de retard (et parfois la veille). Dès que l'utilisateur corrigeait
l'heure/la date pour refléter son heure réelle, la valeur devenait « dans le futur » du point de vue
de l'horloge serveur UTC, et le handler la rejetait (`CreateHarvestLotHandler` : « Jar date cannot be
in the future » / « Harvest date must be before jar date »). Le `catch (Throwable)` du contrôleur
**avalait** l'exception → aucun indice.

**Correctifs :**

1. **Fuseau réglé sur `Europe/Paris`** via script à jeton (`update_option('timezone_string',
'Europe/Paris')`). Corrige aussi l'heure affichée partout ailleurs (commandes, e-mails, journaux),
   qui était décalée de 2 h. Vérifié : `wp_date()` renvoie désormais l'heure locale correcte.
2. **Code durci** (commit sur `main`) : validation des dates de récolte comparée **au jour près**
   (tolère la journée en cours), et le contrôleur **consigne la vraie exception** dans le journal
   d'activité au lieu de l'avaler.

**Diagnostic** mené entièrement par scripts à jeton en **lecture / rollback** (schéma des tables,
rejeu du handler avec nettoyage complet), sans résidu en prod.

---

## Évolutions pilotage / fidélité — déployées le 11 septembre 2026

Déploiement **FTPS ciblé** de 18 fichiers (PHP + Twig, PSR-4, aucune dépendance Composer donc
`vendor/` non touché), OPcache vidé par script à jeton, **18/18 empreintes SHA-256 identiques**
local ↔ prod, `post-deploy-check.sh` (URL non cachées) 200 OK. Commits `0254e09`→`e2cc410` sur `main`.

- **PayPal comme libellé de règlement** — ajouté aux moyens de règlement (Vente, recettes,
  validation), **pas** comme gateway de paiement client au checkout (décision explicite : pas de
  PayPal comme moyen de commande sur le site). S'ajoute la méta `_luziapi_loyalty_excluded` qui
  exclut définitivement une commande du gain de pots (import d'historique).
- **Page « Fidélité » du pilotage** — sélecteur de période en **menu déroulant** (comme l'onglet
  Recettes) et encart « total toutes années » déplacé en bas de page.
- **Erreurs du pilotage** — le **détail de l'exception** s'affiche désormais sous toutes les erreurs
  (inventaire, clients, recettes, vente), plus seulement l'inventaire.
- **Récoltes fiabilisées** — validation des dates au jour près et exception réellement consignée
  (le code durci de la section « Fuseau horaire » ci-dessus est parti dans ce même déploiement).

**Déploiement de suivi le 11 septembre 2026** (commit `87c61b2`, via le nouveau
`scripts/deploy-files.sh` — 1ᵉʳ vrai run) : 7 fichiers **iso-comportement** (extraction de
`ErrorDetailFormatter` testable, ports fidélité `EligiblePotCounter` / `OrderIdentityResolver` pour
tester la garde d'exclusion). Bascule atomique (temp → rename), OPcache vidé, 7/7 SHA identiques,
contrôle live 200 OK. Aucun changement fonctionnel visible.

**Exclusion fidélité activable le 11 septembre 2026** (commit `a957070`, `deploy-files.sh`, 2
fichiers : `inc/order-workflow.php`, `WooCommerceLoyaltyEarningSubscriber`) : nouvelle case
**« Exclure cette commande de la fidélité »** dans la fiche commande (métabox workflow). Elle pose la
méta `_luziapi_loyalty_excluded` et **recalcule** la fidélité à l'enregistrement — le subscriber
écoute désormais aussi `woocommerce_process_shop_order_meta` (priorité 25) : cocher retire les pots
et avantages déjà crédités pour la commande, décocher les réattribue (réconciliation idempotente).
Note de commande tracée à chaque bascule. OPcache vidé, 2/2 SHA identiques, contrôle live 200 OK.

**Correctif de suivi le 11 septembre 2026** (commit `4c5940e`, `deploy-files.sh`, 1 fichier
`inc/order-workflow.php`) : la note de commande d'exclusion est reformulée en **intention**
(« recalcul de la fidélité déclenché ») plutôt qu'en fait accompli, car la réconciliation (prio 25)
peut échouer et n'est tracée que dans Monolog — la note ne doit pas affirmer un recalcul non garanti.
OPcache vidé, 1/1 SHA identiques, contrôle live 200 OK. (Revue `check-pr` de la session : aussi
durci `deploy-files.sh` — refus d'une vérif non-200/JSON inattendu, temporaires en `.ht` non
servables — hors prod car outil non déployé.)

**Avis d'échec de recalcul le 11 septembre 2026** (commit `76104f1`, `deploy-files.sh`, 1 fichier
`WooCommerceLoyaltyEarningSubscriber`) : lors d'une édition de commande dans l'admin, si le recalcul
de fidélité échoue (priorité 25, `\Throwable`), l'opérateur voit désormais un `admin_notice` d'erreur
sur la commande (« le solde de pots peut être incohérent — voir le journal ») en plus de l'alerte
Monolog — la note « recalcul déclenché » ne pouvait pas révéler un échec à l'opérateur présent. Les
hooks automatiques (statut/remboursement) gardent le comportement « journaliser sans casser le
workflow ». OPcache vidé, 1/1 SHA identiques, contrôle live 200 OK. (2ᵉ revue `check-pr` : le
correctif du script `deploy-files.sh` confirmé fail-closed sur les 5 scénarios ; durcissements _low_
appliqués — chemins validés, suppressions signalées, garde curl — hors prod.)

**Recalcul fidélité ciblé — le 11 septembre 2026** (commit `80da506`, `deploy-files.sh`, 2 fichiers
`WooCommerceLoyaltyEarningSubscriber` + `inc/order-workflow.php`) : le recalcul de fidélité ne se
déclenche plus à **chaque** édition de commande, mais **uniquement quand la case « Exclure de la
fidélité » change** (action `luziapi_loyalty_exclusion_changed` émise par le save handler). Motif
(revue pré-mise-en-prod `check-pr`) : comme l'éligibilité se calcule sur la config produit **actuelle**,
recalculer à chaque édition faisait qu'une simple correction d'adresse sur une vieille commande
pouvait **retirer silencieusement des pots légitimement gagnés** si un produit était devenu
non-éligible depuis. Bonus : supprime aussi la dépendance à l'ordre des hooks (20↔25). OPcache vidé,
2/2 SHA identiques, contrôle live 200 OK. (Ajouts hors prod : test du rendu de l'avis admin, rejet de
`..` dans la garde de chemins de `deploy-files.sh`. Contrôle d'intégrité : 8/8 fichiers de la session
identiques prod↔repo.)

## Mise en production 1.1.0 — le 13 septembre 2026

Première release GitFlow depuis la 1.0.0 : branche `release/1.1.0` → `main`, **tag `1.1.0`** (sans
préfixe `v`), back-merge dans `develop`. Déploiement **FTPS ciblé** (`deploy-files.sh --yes`), delta
`80da506 → 776b13c`, **22 fichiers** de code du thème, aucune suppression, aucune nouvelle dépendance
Composer. Bascule atomique `.ht-*`, **OPcache vidé**, **22/22 SHA-256 identiques** prod↔repo, contrôle
post-déploiement 200 sur `/wp-login.php`, `/`, `/boutique/`, `/mon-compte/`.

Changements runtime en prod :

- Vente : « dont offert » fait partie de la quantité (facturer total − offert) — PR #6 ;
- ajout d'un **pot offert** (geste **ou** fidélité) à une commande **existante** depuis la fiche
  commande — PR #7 ;
- **recette retirée automatiquement** quand une commande passe à la corbeille ou est supprimée
  (`WooCommerceOrphanReceiptSubscriber`) — PR #8, corrige le gonflement de l'encaissé par des recettes
  orphelines (cf. section « Recettes orphelines… » plus haut) ;
- version du thème `1.0.0 → 1.1.0` (`functions.php`, `style.css`).

**Aucune migration de schéma.** Outillage ajouté (hors runtime, à lancer manuellement) :
`make audit-receipts-prod` (audit de dérive recette↔commandes, lecture seule — utile pour repérer
d'anciennes recettes orphelines) et `make verify-prod` (intégrité SHA-256 de **tout** le thème).
Le reste de la 1.1.0 (durcissements CI — PHPStan `max`, matrice PHP 8.2/8.3, couverture, audit des
dépendances — et passage de la doc process/agents en anglais) est sans impact prod.

## Mise en production 1.2.0 — le 13 septembre 2026

Déployée avec le **nouveau flux release** : depuis la branche `release/1.2.0` **encore ouverte**
(PR #26 vers `main` non mergée pendant le déploiement), `deploy-files.sh --yes`, delta
`776b13c → 57a2283`, **34 fichiers**, aucune suppression, aucune nouvelle dépendance Composer.
Bascule atomique `.ht-*`, **OPcache vidé**, **34/34 SHA-256 identiques** prod↔repo, contrôle
post-déploiement 200 sur `/wp-login.php`, `/`, `/boutique/`, `/mon-compte/`. Merge dans `main`

- tag `1.2.0` + back-merge `develop` faits **après** ce déploiement, sur feu vert explicite.

Changements en prod :

- **Tableau de pilotage — page « Abonnés »** : liste les inscrits Brevo (compteurs e-mail / SMS,
  recherche), en **lecture seule** (la gestion reste dans Brevo). Contexte `src/Newsletter/`
  (adaptateur Brevo lecture seule : liste 2, attribut `SMS`, blacklists, cache transient,
  dégradation propre sans clé) — la clé API vient de l'option `sib_api_key_v3`.
- **Fiche client** : encart d'abonnement (cases e-mail / SMS **désactivées**, appariées par
  e-mail + téléphone normalisé).
- Barre d'onglets du pilotage factorisée (`PilotageTabs` + `_nav.twig`).
- Version du thème `1.1.0 → 1.2.0`.

**Aucune migration de schéma.** À vérifier côté prod : la page « Abonnés » doit lister les vrais
contacts Brevo (`make audit-receipts-prod` et `make verify-prod` restent disponibles). Le reste de
la 1.2.0 (bascule doc→anglais, correctif filtre `tests-js`, révision du flux release) est sans
impact prod runtime.

_Correctif de suivi le 13 septembre 2026 (commit `b617af1`, `inc/order-workflow.php`, 1/1 SHA,
OPcache vidé, prod saine) :_ le **mode de remise (retrait ⇄ livraison) redevient éditable sur les
commandes « Terminée »** (verrou levé pour ce statut ; les autres étapes de remise et les états
finaux restent verrouillés). Décision : seul l'exploitant modifie les commandes et doit pouvoir
corriger a posteriori. Aussi corrigés en amont, hors prod : la désync des PDF brochure/flyer
(réalignés en prod, `verify-prod` 300/300).

_Correctif de suivi le 13 septembre 2026 (commit `8e39e79`, `inc/woocommerce.php`, 1/1 SHA, OPcache
vidé, prod saine) :_ les métas techniques de ligne `_luziapi_offert` / `_luziapi_loyalty_reward`
sont **masquées** de l'écran de commande admin (`woocommerce_hidden_order_itemmeta`) — seule
l'étiquette lisible « Offert » reste visible. Ajouté aussi (hors prod) : l'inspecteur lecture seule
`make loyalty-inspect-prod ORDER=<id>` pour diagnostiquer un comptage fidélité.

_Correctif/ajout de suivi le 14 septembre 2026 (commit `7a88bf8`, 14 fichiers, 14/14 SHA, OPcache
vidé, prod saine) :_ **① édition des coordonnées client** depuis la fiche « Clients » (e-mail /
téléphone / ville → écrit sur les commandes du client, fusionne les doublons d'identité) ; **③ page
Abonnés** corrigée — inclut désormais les abonnés **SMS-seuls** et affiche le **statut par canal**
(Abonné / Bloqué e-mail et SMS) + compteurs (total, e-mail actifs, SMS actifs, bloqués) ;
suppression de l'**ancienne page « Répertoire clients »** (le `require` retiré la fait disparaître ;
fichier `inc/customer-directory.php` retiré du serveur à la main, non géré par le deploy delta).
② « doublons » : la page « Clients » (projection) était déjà propre — c'était l'ancienne page.

_Évolution de suivi le 14 septembre 2026 (commits `7d9614f` + `9c15bf4`, PR #27 mergée dans
`release/1.2.0` **encore ouverte**, delta `7a88bf8 → c28d7d0`, 18 fichiers, **18/18 SHA**, OPcache
vidé, prod saine, CI verte sur `c28d7d0`) :_

- **① Fiche client entièrement éditable** — remplace l'édition v1 (qui écrivait sur les commandes).
  Le formulaire « Modifier la fiche client » édite prénom, nom, société, adresse postale, code
  postal, ville, pays, e-mail, téléphone, stockés dans une **table dédiée `luziapi_customer_profiles`**
  (indexée par identité, comme la catégorie) qui **surcharge l'affichage sans réécrire les commandes**
  (factures et historique préservés ; une fiche vide retombe sur les données des commandes). La
  correction d'**une** commande précise passe par l'édition **native WooCommerce**.
- **② Autocomplétion d'adresse** via la **Base Adresse Nationale** (`api-adresse.data.gouv.fr`,
  gratuite, sans clé), **côté serveur** via l'endpoint admin-ajax `luziapi_address_search` (nonce +
  capability `edit_shop_orders`) — amélioration progressive, saisie manuelle toujours possible.
- **Migration de schéma `v8 → v9`** : la table `luziapi_customer_profiles` se crée automatiquement au
  premier chargement admin après déploiement (`PilotageSchemaManager`, `dbDelta` idempotent).
- **Fichiers orphelins** du chemin v1 retiré (`UpdateCustomerContactCommand/Handler`,
  `CustomerContactWriter`, `WooCommerceCustomerContactWriter`) : **retirés du serveur à la main** en
  FTPS le 14 septembre 2026 (le deploy delta ne gère pas les suppressions) ; `verify-prod` 311/311.
- Vérifs prod dédiées : `make verify-prod`, `make e2e-customer-profile-prod`,
  `make e2e-address-lookup-prod` (appels BAN interceptés, commande/fiche de test isolées, tout nettoyé).

_Suite le 14 septembre 2026 (commits `c550afa` + `51e5833`, PR #29 + #30 mergées dans
`release/1.2.0` **encore ouverte**, delta `c28d7d0 → 63c1785`, 8 fichiers, **8/8 SHA**, OPcache vidé,
prod saine, CI verte sur `63c1785`) — **abonnement Brevo éditable depuis la fiche client** :_

- L'encart abonnement (e-mail / SMS) devient **inscriptible** : cocher inscrit, décocher désinscrit,
  **écrit directement dans Brevo** (`emailBlacklisted` / `smsBlacklisted`, liste 2, attribut `SMS`) —
  **opt-in direct, sans double opt-in** (consentement réputé recueilli hors ligne). Le contact étant
  identifié par e-mail, le SMS exige un e-mail **et** un mobile ; sans e-mail l'encart reste en lecture
  seule.
- **Confirmation par relecture** : après l'écriture, le contact est **relu** (GET) ; le message de
  succès n'apparaît que si l'état confirmé correspond à la demande, et l'affiche
  (« Confirmé côté Brevo — e-mail : … · SMS : … »).
- Code : `Newsletter/Application/Port/SubscriberWriter` + `BrevoSubscriberWriter` + `UpdateSubscription`
  (command/handler) ; action admin-post `luziapi_update_subscription`. Vérif prod dédiée :
  `make e2e-subscription-write-prod` (appels Brevo interceptés, aucun contact touché).
- **RGPD** : l'opt-in direct suppose le consentement recueilli hors ligne ; repasser en double opt-in
  si besoin.

_Suite le 14 septembre 2026 (commit `b1c5e28`, PR #32 mergée dans `release/1.2.0` **encore
ouverte**, delta `63c1785 → 8b63de0`, 5 fichiers, **5/5 SHA**, OPcache vidé, prod saine) — ergonomie
de l'édition client :_

- **Formulaire d'édition sur 2 colonnes** (1 colonne sous 782 px) et **catégorie client intégrée au
  formulaire** : « Enregistrer la fiche » sauvegarde désormais coordonnées **et** catégorie en une
  seule action (l'ancien formulaire de catégorie séparé est retiré).
- **Autocomplétion d'adresse nationale, sans règle** : biais de proximité retiré, filtre
  `type=housenumber` retiré (les voies seules remontent aussi), liste élargie (10, plafond 15). Une
  même voie présente dans plusieurs communes (ex. « 10 rue de Loches », 6ᵉ au national, à Luzillé)
  redevient accessible — on choisit sur le code postal. `make e2e-address-lookup-prod` valide la
  requête (appels BAN interceptés).
- Merge `main` + tag `1.2.0` + back-merge `develop` **à faire après** feu vert explicite (release
  toujours ouverte).

## Mise en production 1.3.0 — le 19 septembre 2026

Déployée avec le **flux release** : depuis la branche `release/1.3.0` **encore ouverte** (PR #38
vers `main` non mergée pendant le déploiement), `deploy-files.sh --yes`, delta
`f1f5d4c → 5dfc1733`, **64 fichiers**, aucune suppression, aucune nouvelle dépendance Composer.
Bascule atomique `.ht-*`, **OPcache vidé**, **64/64 SHA-256 identiques** prod↔repo, contrôle
post-déploiement 200 sur `/wp-login.php`, `/`, `/boutique/`, `/mon-compte/` (« Production saine »).
Merge dans `main` + tag `1.3.0` + back-merge `develop` **à faire après** ce déploiement, sur feu
vert explicite.

Changements en prod :

- **Programme de fidélité — lancement public** : page publique « Programme de fidélité » (règlement
  versionné, PDF imprimable), référencée dans les **CGV `2026-09-16-v5`** et la **politique de
  confidentialité** ; règlement fidélité **`2026-09-16-v2`**.
- **Fidélité — les pots n'expirent plus** : suppression de la validité 2 ans, les pots se cumulent
  (15 = 1 offert, 30 = 2, etc.).
- **Fidélité — liaison d'identités** : agrège les pots d'un même client sur plusieurs clés de
  contact (auto-liaison prudente, **fusion** et **défusion** manuelles depuis la fiche client),
  **passif** (avantages dus) au tableau de bord, **audit de dérive** lecture seule
  (`make audit-loyalty-prod`), et **backfill** rétroactif sûr (`scripts/backfill-loyalty-prod.sh`).
- **Remise de volume** : corrigée sur les ventes du pilotage (règle partagée `VolumeDiscount`) +
  **rattrapage self-service** sur la fiche commande (réconcilie la recette) + audit
  `make audit-vente-volume-prod`.
- Version du thème `1.2.0 → 1.3.0`.

**Migration de schéma** : la table des liens d'identité fidélité se crée automatiquement au premier
chargement admin après déploiement (`LoyaltySchemaManager`, `dbDelta` idempotent).

**À FAIRE côté prod (activation, hors code) :** ① créer la page WordPress de slug
`programme-de-fidelite` (le routeur ne s'active que si la page existe) ; ② vérifier que les pots du
catalogue sont **« admissibles à la fidélité »** avant tout backfill ; ③ backfill en 2 temps
(`make backfill-loyalty-prod` en simulation, puis `APPLY=1`) ; ④ vérifier que les PDF CGV v5 et
règlement v2 sont servis et joints au 1ᵉʳ e-mail ; ⑤ `make audit-vente-volume-prod` puis corriger
les commandes Vente listées depuis la fiche (dont #305 Mme DEBOUT).

_Activation réalisée le 19 septembre 2026 :_ page `programme-de-fidelite` créée, admissibilités
vérifiées, **backfill fidélité appliqué** (`APPLY=1`) — **66 commandes créditées, 173 pots** (10
déjà au journal ignorées, 43 sans contact, 0 sans pot admissible), vérifié client par client
(`make loyalty-customer-inspect-prod Q="…"`, ex. DEBOUT 35 pots → 2 avantages, GAULTIER 19 → 1).
Rattrapage de la **remise de volume sur #305** (Mme DEBOUT) fait depuis la fiche
(`make audit-vente-volume-prod` repasse au vert). Outillage lecture seule ajouté : détail par
client du backfill (`DETAIL=1`) et inspection client.

_Correctif de suivi le 20 septembre 2026 (commit `514d735`, `inc/order-workflow.php`, 1/1 SHA,
OPcache vidé, prod saine) :_ WooCommerce applique `.form-field input { width: 50% }` (100 % en
`form-field-wide`) à **tous** les inputs, ce qui étirait les **cases à cocher et boutons radio** de
la métabox workflow (fiche commande) en grosses barres/pilules à mi-colonne. Réinitialisé à leur
taille naturelle dans `.luziapi-order-workflow` (selects et champs texte inchangés).

_Suite de QA de release le 20 septembre 2026 (déploiements FTPS ciblés successifs `inc/order-workflow.php`, `resources/views/admin/pilotage/quick-sale.twig`, `assets/js/admin-pilotage.js`, `src/Pilotage/UserInterface/Admin/QuickSaleController.php`, `src/Pilotage/…/GetLoyaltyDashboard/*`, `LoyaltyController.php` ; à chaque fois SHA identiques, OPcache vidé, prod saine, CI verte) :_

- **Cases/radios de la fiche commande** : le premier correctif (classe seule) perdait face à `#order_data … p.form-field input` (spécificité par ID) ; re-scopé sous `#order_data` en `!important` (commit `ae1bc52`).
- **Écran commande rééquilibré** (`6f7cda9`) : les champs workflow sont rendus dans la 1ʳᵉ colonne « Général » (32 %) via `woocommerce_admin_order_data_after_order_details`, d'où une colonne interminable et déséquilibrée. Le conteneur passe en grille : Facturation + Expédition en haut, **« Général » pleine largeur en dessous, sur 2 colonnes** (repli 1 colonne sous 1100 px).
- **Pots offerts multiples** : la fiche commande (`68be29a`→) et la Vente (`8d35ff2`) acceptent plusieurs miels différents en un enregistrement ; la Vente borne le **total** des pots fidélité aux avantages disponibles côté client avec avertissement (`d7f648d`), le serveur bornant déjà (`assertRewardsAffordable`).
- **Onglet Fidélité** (`32d8e82`) : nouvelle section **« Avantages en cours »** (qui a des pots offerts à réclamer) ; le sélecteur de période perd « 2 dernières années » (plus d'expiration) au profit d'un défaut **« Total (toutes années) »**.

## Mise en production 1.3.1 — le 20 septembre 2026

Déployée depuis `release/1.3.1` (PR #40, encore ouverte), `deploy-files.sh --yes`, delta
`32d8e82 → 5e558ec`, **4 fichiers** (`templates/page-programme-de-fidelite.twig`,
`src/Pilotage/Infrastructure/WooCommerce/WooCommerceQuickSaleOrderWriter.php`, `style.css`,
`functions.php`), aucune suppression, aucune nouvelle dépendance. **4/4 SHA identiques**, OPcache
vidé, contrôle post-déploiement 200 sur `/wp-login.php`, `/`, `/boutique/`, `/mon-compte/`. Merge
`main` + tag `1.3.1` + back-merge `develop` à faire après, sur feu vert.

Changements en prod :

- **Vente** : « Envoyer l'e-mail » envoie désormais le **même e-mail LuziApi** que le flux classique
  (confirmation « Terminée » avec récap fidélité + suivi + pied CGV, ou « en attente » si non payée)
  au lieu de l'e-mail WooCommerce standard.
- **Page « Programme de fidélité »** (article 2) : suppression d'une mention périmée de « durée de
  validité » d'un pot (reliquat de l'expiration retirée), cohérente avec l'article 6.
- Version du thème `1.3.0 → 1.3.1`.

**À FAIRE côté prod :** **réimprimer le PDF règlement `2026-09-16-v2`** depuis la page à jour (texte
corrigé ; la règle « sans expiration » ne change pas → millésime inchangé).

---

**Aucun mot de passe ni jeton n'est stocké dans ce dépôt** : les accès vivent dans `.env.local`.
