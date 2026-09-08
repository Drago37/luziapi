# WIP — Refonte du template d'e-mail transactionnel (passation)

> Note de passation à destination de **Codex** (ou tout assistant reprenant le sujet).
> Rédigée le 8 septembre 2026. Lire d'abord `AGENTS.md` (règles de collaboration, git,
> déploiement) et `docs/prod-o2switch.md` (état prod).

## 1. Objectif

Refondre les **e-mails transactionnels de commande** (WooCommerce) : l'actuel est trop
simpliste car il s'appuie sur l'en-tête/pied **par défaut de WooCommerce**. Le client veut
un beau template **à la charte du site**, avec :

- son **logo** ;
- ses **coordonnées** + **SIREN/SIRET** (TVA non applicable, art. 293 B) ;
- un lien **CGV**, un lien **médiation** (dans le **pied de page**, plus « en plein milieu »
  comme aujourd'hui), un lien **vers le site**, les **réseaux sociaux** ;
- un lien **inscription newsletter** avec un rappel **discret et récurrent** à chaque e-mail ;
- respect de la **charte graphique** du site.

## 2. État d'avancement

- **Aucun code d'e-mail modifié.** Seule une **maquette validée visuellement** a été produite.
- Maquette de référence (rendu) : **`docs/maquettes/email-commande-confirmee.html`**
  (ouvrir dans un navigateur). Scénario illustré : **« Commande confirmée »**.
  Publiée aussi en artifact privé : https://claude.ai/code/artifact/666004cb-a59a-41a1-8d1e-ec7a21fd6c94
- ⚠️ La maquette est en **CSS moderne (aperçu navigateur)**, PAS en HTML e-mail. Elle sert
  de **référence visuelle**, pas de code à copier tel quel (voir § 5).

### Questions client encore ouvertes (à confirmer avant/pendant l'implémentation)

1. Logo **dans un rond clair** (comme la maquette) ou **en grand** directement sur fond espresso ?
2. Bloc **newsletter** : niveau de discrétion / emplacement OK, ou plus visible / ailleurs ?
3. Ajouts/retraits éventuels : téléphone cliquable (déjà prévu), horaires, mention récolte,
   avis clients… ?
4. L'emblème abeille de la maquette est **provisoire** → utiliser le **vrai logo** en prod.

## 3. Où vit le code des e-mails

- **Classe e-mail** : `www/wp-content/themes/luziapi/inc/class-luziapi-order-status-email.php`
  (`Luziapi_Order_Status_Email extends WC_Email`). Un **seul** template couvre **tous** les
  statuts ; seul `$this->message` change (`get_message_lines()` renvoie les lignes du corps).
  - Statuts/`message` : `on_hold`, `processing`, `out_for_delivery`, `ready_for_pickup`,
    `completed`, `payment_reminder`, `cancelled`.
  - `get_content_html()` / `get_content_plain()` (~l.225-268) appellent `wc_get_template_html()`
    avec `template_base = LUZIAPI_DIR.'/woocommerce/'` et passent ces variables au template :
    `$message_lines` (list<string>), `$newsletter_url`, `$cgv_url`, `$cgv_version`,
    `$withdrawal_url`, + standard WC (`$order`, `$email_heading`, `$additional_content`,
    `$sent_to_admin`, `$plain_text`, `$email`).
- **Templates** :
  - HTML : `www/wp-content/themes/luziapi/woocommerce/emails/luziapi-customer-order-status.php`
    → **c'est le fichier principal à refondre.** Il appelle actuellement
    `woocommerce_email_header` / `woocommerce_email_footer` (= wrapper WC par défaut, à remplacer)
    et `woocommerce_email_order_details` (tableau de commande WC).
  - Texte brut : `.../woocommerce/emails/plain/luziapi-customer-order-status.php` (à mettre à jour aussi).
- **Enregistrement des e-mails / statuts** : `inc/order-workflow.php` (tableau des définitions,
  hooks, statuts personnalisés `out-for-delivery` / `ready-for-pickup`).

## 4. Charte graphique (reprise de `assets/css/main.css` `:root`)

- Couleurs : `--espresso:#2b1d10` `--bark:#432c16` `--wood:#7a4f26` `--honey:#e0a124`
  `--honey-deep:#8a5410` `--gold:#f2c75a` `--gold-soft:#f7dd9b` `--cream:#fbf1da`
  `--cream-2:#f5e6c4` `--paper:#fffaf0` `--line:#e6d2a8` `--ink:#3a2917` `--muted:#7d6038`.
- Polices : **Fraunces** (serif, titres) + **Hanken Grotesk** (sans, corps), via Google Fonts.
  Mot-symbole : « Luzi » + « Api » (Api en `--gold`).
- Logo : `assets/img/logo.png` (1080×1080, ~121 Ko) →
  `https://www.luziapi.fr/wp-content/themes/luziapi/assets/img/logo.png`.
  ⚠️ Trop gros pour un e-mail : prévoir une version réduite hébergée (ex. 200 px) et un `<img src>`
  hébergé (pas de SVG : Gmail le supprime ; pas de base64 : bloqué par certains clients).

### Structure de la maquette (à reproduire en HTML e-mail)

En-tête espresso (emblème + wordmark) → hero (eyebrow + H1 + « Bonjour {prénom} ») →
corps (`message_lines`) → **récapitulatif** (articles, remise, total, mode de remise, paiement)
→ **CTA** miel → **rappel newsletter discret** (encadré léger) → **pied de page** espresso
(coordonnées, SIREN/SIRET, liens _Le site · CGV · Rétractation · Médiation_, note médiation CM2C,
réseaux FB/IG, mention no-reply).

## 5. Contraintes d'implémentation (HTML e-mail ≠ page web)

- **Tableaux + styles inline obligatoires** : Gmail supprime `<head>`/`<style>`, pas de
  flexbox/grid, CSS limité. La maquette (flex/grid, `<style>`) est à **retranscrire** en
  tables `role="presentation"` + `style="…"` inline.
- **Polices** : fallback `Georgia` (titres) / `Arial` (corps) ; le `<link>` Google Fonts ne
  marche que sur certains clients — le rendu doit tenir sans.
- Largeur ~600 px, images en `width` fixe, `alt` sur le logo.
- Décider : garder `woocommerce_email_order_details` (tableau WC, riche : adresses, etc.) **ou**
  récap custom (comme la maquette). Reco : wrapper custom (en-tête/pied) + garder le tableau WC
  stylé, pour ne pas réimplémenter les totaux/adresses.
- **Données à câbler dans le template** (certaines déjà passées : `cgv_url`, `withdrawal_url`,
  `newsletter_url`) : URL du **site** (`home_url()`), URL **médiation**, **réseaux sociaux** et
  **coordonnées**. Les réseaux + contact viennent du contexte Timber **`contact`**
  (`contact.facebook`, `contact.instagram`, `contact.tel`, `contact.email`, `contact.adresse`,
  `contact.cp_ville`, `contact.nom`) — trouver sa définition (functions.php / inc/ contexte Timber)
  et **exposer ces valeurs au template PHP e-mail** (via `get_content_html()` de la classe).
  Médiation : **CM2C, 49 rue de Ponthieu, 75008 Paris, 01 89 47 00 14, cm2c.net**.
- Mettre à jour la **version texte brut** en parallèle.

## 6. Comment tester le rendu (sans SSH)

- **Local (logique)** : `make e2e-local` (dry-run, aucun envoi).
- **Prod, e-mails RÉELS** : `make e2e-prod-send` → envoie tous les e-mails de test à l'adresse de
  `www/wp-content/themes/luziapi/tools/.e2e-identity.json` (aujourd'hui `anthony.graule@gmail.com`),
  crée puis **supprime** des commandes de test. Idéal pour **voir le nouveau rendu de bout en bout**.
  Détails : `docs/tests-e2e-commandes.md`.
- Déploiement des templates modifiés : **FTPS ciblé** (voir `AGENTS.md` § 3) ; templates PHP
  → **vider l'OPcache** ensuite (`AGENTS.md` § 4). `tools/` n'est pas déployé (exclu).

## 7. Rappels de règles projet (voir AGENTS.md)

- **Ne rien publier / envoyer sans confirmation explicite** : construire le rendu **avec** le
  client, montrer, attendre son feu vert. `make e2e-prod-send` envoie de vrais e-mails → accord requis.
- Commits **en français** sur `main`, **jamais** de trailer `Co-Authored-By`. Ne pas pousser sans demande.
- Prod = **HPOS** ; e-mails en `mail()` natif signé DKIM o2switch ; expéditeur
  **LuziApi `<no-reply@luziapi.fr>`** (réglages WooCommerce `woocommerce_email_from_*`).

## 8. Contexte récent (déjà fait, pour info)

Sessions précédentes (toutes déployées + testées) : délai de règlement virement/WERO porté à
**10 jours ouvrés** (rappel à 5), **CGV révision 2** publiées, garde-fous de statut selon le mode
de remise, e-mails « rappel » et « annulation » (annulation conditionnée à un motif), et l'outil
de **test e2e** (`tools/e2e-orders.php` + `make e2e-*`). Le process de commande a été validé de
bout en bout en prod (12 e-mails reçus, garde-fous OK).

## 9. Prochaines étapes suggérées pour Codex

1. Confirmer les 4 questions ouvertes (§ 2) avec le client.
2. Refondre `woocommerce/emails/luziapi-customer-order-status.php` en HTML e-mail (tables + inline)
   d'après `docs/maquettes/email-commande-confirmee.html`, en exposant les données manquantes
   depuis la classe e-mail.
3. Mettre à jour la version texte brut.
4. Vérifier que **les 7 statuts** rendent bien sur le même gabarit (le corps varie via `message_lines`).
5. Montrer un rendu au client, puis (sur accord) déployer + `make e2e-prod-send` pour valider.
