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
- un lien d'inscription aux actualités par **e-mail et/ou SMS**, avec un rappel **discret et
  récurrent** à chaque e-mail ;
- respect de la **charte graphique** du site.

## 2. État d'avancement

- Le nouveau gabarit est **implémenté localement pour les sept e-mails**, sans déploiement ni
  envoi réel.
- Maquette de référence (rendu) : **`docs/maquettes/email-commande-confirmee.html`**
  (ouvrir dans un navigateur). Scénario illustré : **« Commande confirmée »**.
  Publiée aussi en artifact privé : https://claude.ai/code/artifact/666004cb-a59a-41a1-8d1e-ec7a21fd6c94
- ⚠️ La maquette est en **CSS moderne (aperçu navigateur)**, PAS en HTML e-mail. Elle sert
  de **référence visuelle**, pas de code à copier tel quel (voir § 6).
- Capture du vrai HTML généré et stylé par WooCommerce :
  **`docs/maquettes/email-commande-confirmee-rendu.png`**.
- Le suivi de commande sans compte est reporté en **phase 2** : aucun bouton ni lien de suivi
  n'est présent dans ce premier lot.

### Questions client encore ouvertes (à confirmer avant/pendant l'implémentation)

1. Valider le vrai logo affiché directement sur fond espresso dans le rendu WooCommerce.
2. Bloc **actualités e-mail/SMS** : niveau de discrétion et emplacement OK, ou plus visible ?
3. Ajouts/retraits éventuels : le téléphone est cliquable ; faut-il ajouter horaires, récolte,
   avis clients… ?

### Retours déjà intégrés à la maquette

- La formule « apiculture locale et patiente » a été remplacée par « soutien à l’apiculture
  locale ».
- Le bouton « Suivre ma commande » a été retiré : le paiement invité est le parcours normal,
  la création de compte est désactivée et le site ne propose pas actuellement d’espace de suivi
  accessible à tous les clients. WooCommerce propose toutefois nativement un formulaire de suivi
  par numéro de commande et adresse e-mail de facturation (`[woocommerce_order_tracking]`) : une
  page dédiée permettrait de rétablir ce bouton sans imposer la création d’un compte.
- Le rappel d’inscription mentionne explicitement les deux canaux proposés : actualités LuziApi
  par e-mail et/ou SMS.
- Le vrai logo est utilisé via une variante dédiée de 300 × 300 px et 44 Ko, affichée à 150 px.
- Le récapitulatif natif WooCommerce est conservé et restylé : articles, quantités, remises,
  total, mode de remise, paiement, adresses et métadonnées restent présents.
- Le mode de remise n'est plus répété par le nouveau gabarit WooCommerce 10.8.

## 3. Phase 2 reportée — suivi sans compte

### Deux accès complémentaires

1. **Suivre une commande immédiatement** : formulaire WooCommerce par numéro de commande et
   adresse e-mail de facturation. Le numéro pourra être prérempli depuis le bouton de l’e-mail,
   mais jamais l’adresse e-mail dans l’URL.
2. **Retrouver toutes ses commandes** : saisie de l’adresse e-mail, puis envoi d’un lien magique
   temporaire à cette adresse. Un simple e-mail saisi dans le formulaire ne donnera jamais accès
   directement à l’historique.

### Contenu de l’espace de suivi

- liste paginée de toutes les commandes WooCommerce associées à l’adresse vérifiée, compatible
  HPOS, y compris les commandes invitées ;
- statut, date, numéro et total dans la liste ;
- détail d’une commande : articles, totaux, mode de paiement et mode de remise ;
- historique des statuts LuziApi et notes explicitement destinées au client ;
- aucune note privée d’administration, donnée technique ou information appartenant à une autre
  adresse e-mail.

Les commandes antérieures au déploiement afficheront leur statut courant et leurs éventuelles
notes client existantes. Pour disposer d’une chronologie fiable à l’avenir, les changements de
statut seront enregistrés séparément sous une forme destinée au client ; les anciennes notes
internes ne seront pas exposées ni interprétées aveuglément.

### Sécurité et confidentialité

- lien magique opaque, aléatoire, à durée courte, consommé contre une session temporaire puis
  retiré de l’URL ;
- réponse identique que l’adresse corresponde ou non à des commandes, pour empêcher
  l’énumération des clients ;
- limitation des demandes par adresse et par origine afin d’éviter l’envoi abusif d’e-mails ;
- cookie de session sécurisé, `HttpOnly` et `SameSite=Lax` ; sortie explicite de l’espace ;
- page en `noindex` et strictement exclue du cache. La non-mise-en-cache PowerBoost devra être
  vérifiée en production avec deux sessions distinctes avant ouverture au public.

### Intégration future aux e-mails

- rétablir le bouton « Suivre ma commande » seulement lorsque la page fonctionne ;
- faire pointer les sept e-mails vers la page de suivi avec le numéro de commande prérempli ;
- afficher dans chaque e-mail le rappel discret d’inscription aux actualités par e-mail et/ou SMS ;
- conserver une version texte brut complète avec l’URL de suivi ;
- ne créer la page en production, ne déployer et n’envoyer les e-mails de test qu’après accord
  explicite.

### Vérifications prévues

- bonne et mauvaise combinaison numéro/e-mail ;
- plusieurs commandes invitées avec la même adresse ;
- adresse inconnue et limitation des demandes de liens ;
- expiration, rejeu et nettoyage du lien magique ;
- séparation stricte entre notes client et notes privées ;
- sept statuts LuziApi, commandes annulées comprises ;
- absence de fuite via le cache, les URL, les journaux et les messages d’erreur ;
- rendu mobile et test des versions HTML et texte brut.

## 4. Où vit le code des e-mails

- **Classe e-mail** : `www/wp-content/themes/luziapi/inc/class-luziapi-order-status-email.php`
  (`Luziapi_Order_Status_Email extends WC_Email`). Un **seul** template couvre **tous** les
  statuts ; seul `$this->message` change (`get_message_lines()` renvoie les lignes du corps).
  - Statuts/`message` : `on_hold`, `processing`, `out_for_delivery`, `ready_for_pickup`,
    `completed`, `payment_reminder`, `cancelled`.
  - `get_content_html()` / `get_content_plain()` appellent `wc_get_template_html()` avec
    `template_base = LUZIAPI_DIR.'/woocommerce/'` et fournissent les messages, la conclusion,
    le contact centralisé, le logo et tous les liens du pied de page.
- **Templates** :
  - HTML : `www/wp-content/themes/luziapi/woocommerce/emails/luziapi-customer-order-status.php`
    contient maintenant l'enveloppe complète en tableaux et conserve les hooks de détails,
    métadonnées et coordonnées client de WooCommerce.
  - Texte brut : `.../woocommerce/emails/plain/luziapi-customer-order-status.php`, mis à jour en
    parallèle.
- **Styles dédiés** : `assets/css/email.css`, chargés uniquement par la classe LuziApi puis
  transformés en styles inline par WooCommerce.
- **Logo dédié** : `assets/img/logo-email.png` (300 × 300 px, 44 Ko).
- **Coordonnées centralisées** : `luziapi_contact_details()` dans `inc/timber.php`, utilisée à la
  fois par Timber et les e-mails.
- **Enregistrement des e-mails / statuts** : `inc/order-workflow.php` (tableau des définitions,
  hooks, statuts personnalisés `out-for-delivery` / `ready-for-pickup`).

## 5. Charte graphique (reprise de `assets/css/main.css` `:root`)

- Couleurs : `--espresso:#2b1d10` `--bark:#432c16` `--wood:#7a4f26` `--honey:#e0a124`
  `--honey-deep:#8a5410` `--gold:#f2c75a` `--gold-soft:#f7dd9b` `--cream:#fbf1da`
  `--cream-2:#f5e6c4` `--paper:#fffaf0` `--line:#e6d2a8` `--ink:#3a2917` `--muted:#7d6038`.
- Polices : **Fraunces** (serif, titres) + **Hanken Grotesk** (sans, corps), via Google Fonts.
  Mot-symbole : « Luzi » + « Api » (Api en `--gold`).
- Logo source : `assets/img/logo.png` (1080×1080, ~121 Ko). L'e-mail utilise sa variante réduite
  `assets/img/logo-email.png`, qui sera hébergée avec le thème (pas de SVG ni de base64).

### Structure de la maquette (à reproduire en HTML e-mail)

En-tête espresso (emblème + wordmark) → hero (eyebrow + H1 + « Bonjour {prénom} ») →
corps (`message_lines`) → **récapitulatif** (articles, remise, total, mode de remise, paiement)
→ **rappel newsletter discret** (encadré léger) → **pied de page** espresso
(coordonnées, SIREN/SIRET, liens _Le site · CGV · Rétractation · Médiation_, note médiation CM2C,
réseaux FB/IG, mention no-reply).

## 6. Contraintes d'implémentation (HTML e-mail ≠ page web)

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

## 7. Comment tester le rendu (sans SSH)

Contrôles locaux du premier lot déjà réalisés :

- syntaxe PHP valide et `git diff --check` propre ;
- PHP-CS-Fixer : aucun écart ;
- PHPStan avec 1 Go : aucune erreur ;
- PHPUnit : 56 tests, 73 assertions ;
- test e2e local en dry-run : 30/30 assertions, dont le gabarit complet sur les sept e-mails ;
- rendu HTML réellement généré et stylé par WooCommerce contrôlé dans un navigateur.

- **Local (logique)** : `make e2e-local` (dry-run, aucun envoi).
- **Prod, e-mails RÉELS** : `make e2e-prod-send` → envoie tous les e-mails de test à l'adresse de
  `www/wp-content/themes/luziapi/tools/.e2e-identity.json` (aujourd'hui `anthony.graule@gmail.com`),
  crée puis **supprime** des commandes de test. Idéal pour **voir le nouveau rendu de bout en bout**.
  Détails : `docs/tests-e2e-commandes.md`.
- Déploiement des templates modifiés : **FTPS ciblé** (voir `AGENTS.md` § 3) ; templates PHP
  → **vider l'OPcache** ensuite (`AGENTS.md` § 4). `tools/` n'est pas déployé (exclu).

## 8. Rappels de règles projet (voir AGENTS.md)

- **Ne rien publier / envoyer sans confirmation explicite** : construire le rendu **avec** le
  client, montrer, attendre son feu vert. `make e2e-prod-send` envoie de vrais e-mails → accord requis.
- Commits **en français** sur `main`, **jamais** de trailer `Co-Authored-By`. Ne pas pousser sans demande.
- Prod = **HPOS** ; e-mails en `mail()` natif signé DKIM o2switch ; expéditeur
  **LuziApi `<no-reply@luziapi.fr>`** (réglages WooCommerce `woocommerce_email_from_*`).

## 9. Contexte récent (déjà fait, pour info)

Sessions précédentes (toutes déployées + testées) : délai de règlement virement/WERO porté à
**10 jours ouvrés** (rappel à 5), **CGV révision 2** publiées, garde-fous de statut selon le mode
de remise, e-mails « rappel » et « annulation » (annulation conditionnée à un motif), et l'outil
de **test e2e** (`tools/e2e-orders.php` + `make e2e-*`). Le process de commande a été validé de
bout en bout en prod (12 e-mails reçus, garde-fous OK).

## 10. Prochaines étapes

1. Faire valider la capture du rendu WooCommerce et intégrer les derniers retours visuels.
2. Committer localement sur `main` en français uniquement après finalisation du lot.
3. Sur accord explicite : déployer les fichiers ciblés, vider l'OPcache et vérifier les empreintes.
4. Sur un second accord explicite : lancer les e-mails réels de test vers l'identité e2e.
5. Traiter ensuite le suivi de commande sans compte comme un lot séparé.
