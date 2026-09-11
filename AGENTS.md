# Instructions pour les agents (LuziApi)

Ce fichier est la mémoire de travail du dépôt : il rassemble les règles de collaboration et
les connaissances sur le projet qui ne se déduisent pas du code. Il est destiné à être lu par
n'importe quel assistant de code (Codex, Claude Code, etc.).

> La documentation du dépôt est en **français** (README, DEPLOIEMENT, historique git) ; garder
> cette langue pour les commits, la doc et les échanges. Le **code** reste en anglais.
>
> Avant de modifier le code, lire également **[`CONTRIBUTING.md`](CONTRIBUTING.md)** : il définit
> l'architecture hexagonale, le DDD pragmatique et les conventions PHP/Composer attendues.

---

## 1. Règles de collaboration (à respecter systématiquement)

### Confirmer avant toute publication

Pour **toute action visible depuis l'extérieur** — publier un article WordPress, envoyer une
campagne / newsletter Brevo, modifier une page en prod, déployer — : construire d'abord le
contenu **avec** l'utilisateur, montrer le rendu ou le résultat final, puis **attendre une
confirmation explicite** avant d'exécuter. Ne jamais mettre en ligne ni déclencher un envoi de
sa propre initiative.

_Pourquoi :_ l'utilisateur veut garder la main et relire avant. Formulé explicitement :
« je veux le travailler avec toi avant, comme tout le temps… tu dois avoir ma confirmation pour
publier ensuite ».

Vaut aussi pour l'**auto-envoi de la newsletter**, déclenché par la première publication d'un
article (voir [docs/prod-o2switch.md](docs/prod-o2switch.md)) : publier un article, c'est
envoyer un e-mail + un SMS à toute la liste.

### Ne jamais retirer une fonctionnalité sans le dire

Ne **jamais supprimer, retirer ou désactiver une fonctionnalité existante** sans demander
confirmation au préalable — **même comme simple effet de bord** d'une autre modification.
Par défaut, préserver l'existant : reporter / réimplémenter la fonctionnalité dans le nouveau
contexte plutôt que l'abandonner.

_Pourquoi :_ en déplaçant le bouton panier du header vers les boutons flottants, le mini-panier
déroulant (jugé pratique) a été retiré au passage sans prévenir. Mal pris.

### Git : commit direct sur `main`

Sur ce dépôt perso, **committer directement sur `main`** — pas de branche de feature, pas de PR.
Le flux « branche dédiée + PR » des règles globales vise les repos pro et ne s'applique pas ici.

- Messages de commit en **français** (cohérence avec tout l'historique).
- **Jamais** de trailer `Co-Authored-By:` — préférence explicite, un commit a déjà été refusé et
  l'historique nettoyé pour l'enlever.
- Ne pas pousser sans demande explicite.

### Déployer = feu vert explicite

« Déploie » est une action de publication : elle demande un accord clair, comme le reste.

### Garde de déploiement : code poussé + CI verte (bloquant)

**Ne jamais déployer si le code n'est pas d'abord poussé sur `main` ET la CI verte sur `HEAD`.**
Ordre imposé : commit → push sur `main` → attendre la CI verte → et alors seulement, sur feu vert
explicite, déployer. Une vérif locale ne remplace pas la CI. `scripts/deploy-files.sh` applique
cette garde automatiquement et **refuse** de déployer sinon (arbre propre, `main` synchro avec
`origin/main`, run CI « Qualité du thème » `success` sur le SHA courant).

_Pourquoi :_ règle posée explicitement — « le code doit être pushé et la CI verte obligatoirement,
sinon on bloque le déploiement ». Convention actuelle : **`main` = la prod à jour** (pas encore de
gitflow ni de tag de release ; ça viendra pour les releases).

### Après chaque déploiement : consigner (obligatoire, sans qu'on le demande)

**Tout déploiement doit être consigné dans [docs/prod-o2switch.md](docs/prod-o2switch.md)** dans la
foulée, sans attendre qu'on le demande : commit(s) déployé(s), fichiers, résultat des vérifications
(SHA, OPcache, contrôle live) et ce qui change côté prod. Mettre aussi à jour la mémoire projet si
utile. Ne jamais laisser un déploiement non documenté.

_Pourquoi :_ demande explicite de l'utilisateur — « il faut le faire après chaque déploiement, me
répéter c'est pénible ». C'est un automatisme attendu, pas une option.

### Tenir cette documentation à jour

Ces fichiers **sont** la mémoire du projet : c'est la seule qui soit partagée entre les postes et
entre les assistants. Dès qu'une session fait apparaître quelque chose qui mérite d'être retenu —
préférence de l'utilisateur, piège serveur, décision de configuration, changement d'état de la
prod — l'écrire ici dans la foulée, sans attendre qu'on le demande :

- règle de comportement, flux git, méthode de déploiement, piège serveur → **`AGENTS.md`** ;
- état de la production → **`docs/prod-o2switch.md`**.

Ne jamais y écrire d'identifiant (voir § 6). `CLAUDE.md` n'est qu'un pointeur vers ce fichier :
ne rien y dupliquer.

---

## 2. Carte du projet

- **README.md** — structure du dépôt et installation locale (Docker, `make install`).
- **CONTRIBUTING.md** — architecture cible, séparation hexagonale, DDD, Composer et conventions de
  qualité. À lire avant toute modification du code.
- **DEPLOIEMENT.md** — mise en ligne et mise à jour du thème (`make deploy`, FTPS).
- **docs/prod-o2switch.md** — état de la prod, architecture fonctionnelle, pièges serveur.
  À lire avant toute intervention touchant la production.
- **docs/processus-metier-commandes.md** — processus réel de vente et de traitement des commandes,
  diagramme, automatismes, notifications et écarts de configuration.
- **docs/fidelite.md** — programme de fidélité (« 10 pots achetés, le 11e offert »), architecture
  du module `src/Loyalty/` et suivi des lots de l'issue #4.
- **docs/modeles-sms-brevo.md** — modèles de SMS.
- **docs/** — supports imprimés et visuels :
  - `print/` — brochure, flyer et carte de visite. Deux variantes par document : sans suffixe
    (destinée au web) et `-print` (fonds perdus / profil pour l'imprimeur).
  - `sources/` — fichiers éditables zippés dont sont tirés les PDF de `print/`.
  - `etiquettes/` — étiquettes de pots ; `social/` — visuels des réseaux sociaux.
- **prod-mu-plugins/** — les mu-plugins de production, versionnés ici mais **déployés à part**
  (le compte FTP ne les voit pas, voir plus bas).

### Brochure et flyer téléchargeables

`docs/print/` est la **source** ; les versions publiées sur le site sont des **copies** dans
`www/wp-content/themes/luziapi/assets/docs/` (variantes web uniquement, pas les `-print`). Les
deux se désynchronisent facilement — constaté : le thème et la prod servaient une version plus
ancienne que les sources. Quand un de ces PDF change :

1. recopier `docs/print/LuziApi-{brochure,flyer}.pdf` dans `assets/docs/` ;
2. corriger la taille annoncée dans la colonne « Documents » de
   `templates/partials/footer.twig` — elle est **écrite en dur** (convention : base 1024,
   `Ko` entier, `Mo` à une décimale) ;
3. déployer les 3 fichiers en FTPS ciblé (§ 3) et vérifier le `content-length` servi.

Seul le **thème** `www/wp-content/themes/luziapi/` est versionné : cœur WordPress, plugins et
médias sont fournis par l'hébergeur ou par Docker.

### Versionner les CGV sans perdre la preuve client

Le texte public des CGV vit dans `templates/page-conditions-generales-de-vente.twig`. Sa version
est déclarée dans `inc/commerce-legal.php` et possède un PDF immuable du même millésime dans
`assets/docs/` (par exemple `LuziApi-CGV-2026-09-07.pdf`). Lors d'une évolution :

1. changer la constante de version et créer un **nouveau** nom de PDF ;
2. ne jamais écraser ni supprimer un PDF déjà accepté par des clients ;
3. vérifier que le premier e-mail de confirmation joint la nouvelle version ;
4. conserver dans la commande la version et l'horodatage d'acceptation ;
5. déployer le nouveau PDF avec les fichiers PHP/Twig correspondants, puis vider l'OPcache.

Les conventions et attestations du médiateur sont confidentielles et restent hors du dépôt. Seules
ses coordonnées publiques nécessaires aux CGV sont versionnées.

### Nommage des fichiers destinés à un imprimeur

Pour une variante préparée pour un site d'impression, mettre uniquement le nom du site en
suffixe final : par exemple `LuziApi-carte-recto-saxoprint.pdf`. Ne pas ajouter au nom les
détails techniques (`x4`, `vectorise`, etc.), qui se vérifient dans le fichier lui-même.

Tests : `phpunit.xml.dist` + `tests/` (logique pure). CI : PHPStan + CS-Fixer sur le thème
(`.github/workflows/ci.yml`). Test **de bout en bout** du process de commande (statuts,
e-mails, échéance de règlement, annulation, stock) : `tools/e2e-orders.php` + doc
[docs/tests-e2e-commandes.md](docs/tests-e2e-commandes.md) — à rejouer (local `make e2e-local`
ou prod) à chaque changement du workflow des commandes.
Le suivi client sans compte possède en plus son test d’intégration local
`make e2e-tracking-local` et sa documentation dans
[docs/tests-suivi-commandes.md](docs/tests-suivi-commandes.md).
La fidélité (`src/Loyalty/`) a ses tests unitaires `tests/Loyalty/`, `tests/Pilotage/`
et ses tests d’intégration locaux `make e2e-loyalty-local` (pots/avantages),
`make e2e-discount-local` (remise remerciement) et `make e2e-vente-loyalty-local`
(chemin réel de la Vente : offert + fidélité + remise + stock), plus un test **de
bout en bout sur la prod** rejouable `make e2e-loyalty-prod` (produit + commande de
test isolés, statut « Terminée » via `set_status` donc aucun e-mail ni recette,
tout nettoyé) — voir [docs/fidelite.md](docs/fidelite.md).
La case « Exclure de la fidélité » de la fiche commande a en plus son e2e
d’intégration `make e2e-exclusion-local` et son test **de bout en bout sur la prod**
`make e2e-exclusion-prod` : ils pilotent le **vrai chemin admin** (contexte
administrateur + nonce + `$_POST`, appel de `luziapi_save_admin_order_workflow`, qui
pose la méta puis émet `luziapi_loyalty_exclusion_changed` recalculée par l’abonné),
et vérifient aussi que les hooks sont câblés (isolé, aucun e-mail, tout nettoyé).
L'**auto-envoi newsletter** (mu-plugin `luziapi-newsletter-autosend`) a de même
`make e2e-newsletter-local` et `make e2e-newsletter-prod` : ils vérifient que la
publication **planifie** (sans envoyer), qu'une réédition ne re-planifie pas, et que
l'exécution différée passe par le bon canal — **en interceptant les appels Brevo**
(`pre_http_request`) pour qu'**aucun e-mail/SMS ne parte** (le seul canal de sortie
est court-circuité), l'article de test étant supprimé et l'event dé-planifié. La cible
locale installe d'abord le mu-plugin dans `www/wp-content/mu-plugins/` (absent en dev).

> **Couvrir en e2e ce que l’unitaire ne peut pas.** Une fonctionnalité dont le
> comportement passe par le **chemin réel WordPress/WooCommerce** (soumission d’un
> formulaire admin, ordre/branchement des hooks, capabilities, nonce, `$_POST`) n’est
> pas couvrable par les tests « logique pure » de `tests/`. On la couvre par un e2e
> d’intégration qui rejoue ce chemin : un cœur partagé `tools/e2e-*.php`, un wrapper
> local WP-CLI (`make …-local`) et un wrapper prod à jeton (`scripts/…-prod.sh`,
> `make …-prod`) — isolé et auto-nettoyé, aucun e-mail. Modèle de référence :
> `tools/e2e-exclusion*.php` + `scripts/e2e-exclusion-prod.sh`.

**Journalisation.** Logger PSR-3 partagé `luziapi_logger()` (Monolog, `inc/logger.php`), en
**fingers-crossed** : chaque requête bufferise tout mais n'écrit dans `wp-content/luziapi-logs/prod.log`
**que si un `ERROR` survient** (le buffer part alors comme contexte) — une requête saine n'écrit rien.
`luziapi_register_error_handler()` (appelé dans `functions.php`) route erreurs PHP + exceptions non
attrapées + fatals dans Monolog et coupe `log_errors`/`display_errors` : **on n'utilise plus le
`error_log` natif** (il avait atteint 16 Go, inondé par des warnings WooCommerce — voir
docs/prod-o2switch.md). Processors : Web (URL/méthode/IP/referer/user-agent), Introspection, mémoire.
Dossier hors du thème, protégé `.htaccess`, non versionné, non déployé (créé au runtime) ; pour lire
les logs de prod, passer par le script à jeton (§ 4). Complété par une **rotation quotidienne**
(`RotatingFileHandler`, 14 jours), une **alerte e-mail sur `ERROR`** (`WordPressMailerHandler`,
throttlée à 1 message / 2 min) et le **correctif à la source** du warning WooCommerce
« Undefined array key state » (filtre `woocommerce_cart_shipping_packages` dans `inc/woocommerce.php`,
qui garantit `country/state/postcode/city/address` sans changer le matching des zones). L'issue #5
est **close, ces trois suites étant faites**.

---

## 3. Déploiement — choisir la bonne méthode

### Quelques fichiers du thème changent, sans nouvelle dépendance Composer

→ **`scripts/deploy-files.sh`** (upload FTPS ciblé), pas `make deploy`.

Le script fait tout le ciblé de bout en bout : garde de déploiement (§ 1 — code poussé + CI verte),
calcul du delta `git diff <base>..HEAD` (base = dernier déploiement mémorisé dans le fichier local
non versionné `scripts/.last-deploy`, ou passée en argument), upload des seuls fichiers de code du
thème, OPcache vidé + comparaison SHA-256 local ↔ prod par script à jeton, puis
`post-deploy-check.sh`. En cas de doute, la marche à suivre manuelle reste ci-dessous.

_Pourquoi :_ `make deploy` lance `composer-prod` **dans le conteneur Docker `wordpress`** (donc
échoue si Docker n'est pas démarré : « service wordpress is not running »), puis un
`lftp mirror -R --delete` de **tout le thème, `vendor/` inclus** → des milliers de fichiers en
FTPS, lent et sujet aux timeouts (constaté : timeout à 2 min rien qu'au listing). Disproportionné
pour 2-3 fichiers.

Marche à suivre :

1. Lire les `DEPLOY_FTP_*` dans `.env.local`. Le compte (`luziapi-deploy@luziapi.fr`) est
   **chrooté sur le dossier du thème** : `DEPLOY_FTP_PATH=.` — la racine FTP **est**
   `wp-content/themes/luziapi/`.
2. Uploader chaque fichier dans son sous-dossier, avec
   `ftp:ssl-force true; ssl:verify-certificate yes; passive-mode true` :
   ```
   lftp … -e "put -O <FTP_PATH>/inc inc/shop.php; put -O <FTP_PATH>/assets/css assets/css/main.css; bye"
   ```
3. Si des **fichiers PHP** ont changé → **vider l'OPcache** (voir § 4). CSS/Twig seuls : inutile.
4. **Vérifier** : SHA-256 local vs serveur (`hash_file` via le script à jeton) **et** rendu HTTP
   réel (curl + grep sur les pages). Attention au cache PowerBoost : tester aussi sans
   cache-buster.

### Une dépendance Composer change (nouveau `require`)

Il faut régénérer `vendor/` en version prod **et déployer le vendor _complet_**, pas seulement le
nouveau paquet + l'autoload. `composer` regénère un **autoload cohérent avec tout le vendor local** :
si prod a un vendor **incomplet** (séquelle d'un déploiement interrompu), le nouvel autoload va
`require` en dur des fichiers (ex. `react/promise` en _files-autoload_) **absents de prod** → fatal,
site en 500. Marche à suivre sûre :

1. `composer install --no-dev --optimize-autoloader` (dans le conteneur : `docker compose exec … `),
   pour un vendor **prod-only** (~500 fichiers, sans PHPStan/PHPUnit/CS-Fixer).
2. Mirror FTPS du **vendor entier** (`mirror -R --no-perms`, sans `--delete`) — pas juste le delta.
3. Vider l'OPcache, puis `make deploy-check-live` (URL non cachées).
4. Restaurer les dépendances de dev en local : `composer install`.

Constaté le 10 septembre 2026 en déployant Monolog : prod a fait un 500 car son vendor avait perdu
`react/promise` (déploiement partiel du 9). Détails : [docs/prod-o2switch.md](docs/prod-o2switch.md).
`make deploy` régénère et mirror aussi, mais son mirror complet du thème est fragile à l'interruption
(§ ci-dessus) : préférer la régénération `--no-dev` + mirror ciblé du seul `vendor/`.

> **Artefacts de dev/test jamais déployés.** Le mirror `make deploy` exclut, via la variable
> `DEPLOY_EXCLUDES` du `Makefile`, tout ce qui ne sert pas au runtime : `tools/`, `tests-js/`,
> `node_modules/`, `package.json` / `package-lock.json`, config CS-Fixer / PHPStan, `README.md`.
> Ajouter tout nouvel outil ou dossier de test à cette liste. (Piège make corrigé au passage : un
> glob contenant `#` doit être échappé `\#`, sinon make commente la fin de la ligne.)

### Un mu-plugin change

Le compte FTP est chrooté sur le thème et ne voit **pas** `wp-content/mu-plugins/`. Déploiement
par **script à jeton** (§ 4) : push FTPS d'un `_deploy.php` + payload base64 dans le dossier du
thème, appel HTTPS avec le jeton, écriture dans `WPMU_PLUGIN_DIR` avec sauvegarde
`.bak-<horodatage>` et `opcache_reset()`, puis suppression du script.

---

## 4. Exécuter une action sur le serveur (ni SSH ni cPanel)

Technique du **script PHP à jeton** :

1. Déposer un petit script PHP protégé par un jeton dans le dossier du thème (`lftp put`).
2. L'appeler en HTTPS : `/wp-content/themes/luziapi/_xxx.php?k=TOKEN`. Le script fait
   `require ../../../wp-load.php` pour disposer de tout WordPress / WooCommerce.
3. **Le supprimer** une fois l'opération terminée.

Permet : créer/mettre à jour du contenu via l'API WP/WooCommerce, lire des options ou les logs
(`../../../error_log`), écrire dans `WPMU_PLUGIN_DIR`, appeler `opcache_reset()`, comparer des
empreintes de fichiers.

> `wp-config.php` n'est pas modifiable par FTP → passer par un **mu-plugin** pour définir des
> constantes.

### Pièges serveur à connaître

- **OPcache** sert l'ancienne version d'un fichier PHP modifié. Après tout déploiement de PHP du
  thème (`inc/*.php`, `functions.php`…), appeler `opcache_reset()`. Constaté : un nouveau hook
  WooCommerce restait invisible tant que l'OPcache n'était pas vidé. **Vaut aussi pour
  `make deploy`.**
- **Liste des commandes WooCommerce** : la production utilise **HPOS**. Avec WordPress 7.1 et
  WooCommerce 10.8.1, cliquer une case de sélection peut ouvrir la commande au lieu de la cocher
  (même symptôme que le bug amont `woocommerce/woocommerce#67906`). Le contournement ciblé dans
  `inc/woocommerce.php` couvre les écrans HPOS et historique, bloque uniquement la remontée du
  clic de la case et conserve le reste de la ligne cliquable. Ne le retirer qu'après vérification
  d'un correctif amont.
- **Cache PowerBoost** (o2switch) sert parfois une page périmée.
- **Cache navigateur CSS/JS** : le thème enqueue `main.css` / `main.js` avec `filemtime()` comme
  `?ver` (et non plus `LUZIAPI_VERSION`, figé à `1.0.0`). C'était la cause de changements de style
  invisibles. Quand un changement CSS « ne s'affiche pas », vérifier le `?ver` réellement servi.
- **Déploiement interrompu = prod en 500, masqué par le cache.** Un `make deploy` (mirror de tout le
  thème) coupé en cours (timeout, réseau, crédits épuisés) laisse `src/` **partiellement** uploadé.
  Comme `functions.php` boote `PilotageServiceProvider::boot()`, une classe manquante provoque un
  **fatal sur toute page chargeant le thème** — mais PowerBoost continue de servir la home en 200,
  cachant la panne. Diagnostic : tester une **URL non cachée** (`/wp-login.php`, `/mon-compte/`, ou la
  home avec `?nocache=…`) et comparer `find src/ | wc -l` prod vs repo. Réparation : re-`mirror -R`
  (sans `--delete`) du dossier concerné, `opcache_reset()`, puis vérifier par **SHA-256**. Constaté le
  10 septembre 2026 (deploy interrompu faute de crédits, prod à 27/109 fichiers `src/Pilotage/`, site
  en 500 ~24 h). Détails dans [docs/prod-o2switch.md](docs/prod-o2switch.md). Préférer le **FTPS ciblé et
  vérif SHA** à `make deploy`.
- **`dbDelta` ne change pas la nullabilité d'une colonne existante.** Constaté le 10 septembre 2026 :
  la colonne `sequence_number` de `luziapi_receipts`, créée jadis en `NOT NULL`, l'est restée malgré
  un schéma passé à `NULL` — le dépôt insérant NULL puis le renseignant, **tout enregistrement de
  recette échouait** silencieusement. Pour un changement de nullabilité (ou de type), ajouter un
  `ALTER TABLE … MODIFY` explicite dans la migration versionnée (`PilotageSchemaManager`), jamais se
  fier au seul `dbDelta`.

### Processus de remise en production depuis le 6 septembre 2026

- Deux choix au checkout : **retrait au domicile de LuziApi à Luzillé sur rendez-vous** et
  **livraison gratuite à Luzillé ou Bléré sur rendez-vous**.
- Le code postal 37150 couvre d'autres communes : la livraison doit être validée sur le triplet
  pays France + code postal 37150 + ville normalisée (`Bléré` ou `Luzillé`), jamais sur le seul
  code postal.
- Le retrait reste disponible hors de ces deux villes ; son lieu est fixe. Seuls le jour et
  l'heure du rendez-vous sont à convenir.
- L'e-mail de retrait lit l'adresse de la boutique depuis les réglages WooCommerce : ne pas la
  dupliquer dans le code du workflow des commandes.
- Après **En cours**, le traitement se sépare en **En cours de livraison** ou **Prête au retrait**,
  puis revient à **Terminée** après la remise effective.
- Les textes validés, leurs déclencheurs et le diagramme sont consignés dans
  `docs/processus-metier-commandes.md`.

---

## 5. Ce qu'il ne faut pas refaire

Décisions prises volontairement — ne pas les défaire sans en parler :

- **TranslatePress** : à ne pas réinstaller. Récursion infinie via le hook `gettext` quand
  Contact Form 7 est rendu par `do_shortcode` dans Twig → erreur 500. Le multilingue passe par une
  page `/en/` dédiée.
- **Modules de tracking Jetpack** (`stats`, `woocommerce-analytics`, `blaze`, `subscriptions`) :
  désactivés pour raison RGPD (données envoyées à Automattic/USA sans consentement). Ne pas les
  réactiver sans mettre à jour la politique de confidentialité.
- **cookieadmin + cookieadmin-pro** : c'est un combo gratuit + extension, **pas** un doublon.
- **SMTP authentifié `mail.luziapi.fr:465`** : ne fonctionne pas (Exim répond 250 OK mais Gmail ne
  reçoit jamais). Les e-mails partent en envoi natif `mail()`, signé DKIM par o2switch.
- **Formulaire natif Brevo `[sibwp_form]`** : inutilisable sur o2switch. Remplacé par un formulaire
  maison + route REST (voir docs/prod-o2switch.md).
- **Création de commande = point d'entrée unique** : toute commande manuelle passe par la **Vente**
  du tableau de pilotage (ex-« Vente rapide », renommée). L'écran natif WooCommerce de création
  (`wc-orders&action=new` et le legacy `post-new.php?post_type=shop_order`) est **redirigé** vers la
  Vente et son bouton « Ajouter une commande » masqué (`inc/woocommerce.php`) ; l'**édition** et le
  **remboursement** des commandes existantes restent disponibles. C'est la condition d'une future
  fidélité fiable (un seul chemin de création). La Vente préremplit les coordonnées depuis le
  répertoire client (invités sans compte : source = le carnet maison, jamais la liste des
  utilisateurs WordPress). Ne pas rétablir la création native sans en parler. Test :
  `make e2e-vente-local` (voir [docs/tests-vente.md](docs/tests-vente.md)).
- **Recette = commande « Terminée » (enregistrement automatique).** Règle métier validée : le statut
  « Terminée » vaut preuve d'encaissement. Un subscriber (`WooCommerceReceiptSubscriber`) porte donc
  automatiquement la recette au registre au passage à ce statut, idempotent (solde manquant seulement),
  en excluant les commandes issues de la Vente qui gèrent déjà la leur. Le rapprochement assisté et la
  saisie manuelle restent disponibles pour les cas particuliers, mais ne sont plus la voie normale. Ne
  pas revenir à un rapprochement manuel obligatoire sans en parler. Rattrapage de l'historique :
  `make backfill-receipts-local` (`LUZIAPI_BACKFILL_DRY=1` pour simuler). Tests : `make e2e-receipt-local`
  et `RecordOrderReceiptHandlerTest`.
- **Fidélité : « offert » et « fidélité » sont deux choses distinctes** (décision explicite, ne pas
  fusionner). Depuis la Vente : « **offert** » = geste commercial libre (ligne à 0 €, affichée
  « offert », sortie du stock, **sans** impact fidélité) ; « **Fidélité → offrir un pot** » = pot
  offert au titre du programme (0 €, sorti du stock **et** consomme un avantage, borné aux avantages
  disponibles). Les deux sont exclus du gain de pots. Détails et métas de ligne dans
  [docs/fidelite.md](docs/fidelite.md). Module `src/Loyalty/`, tests `make e2e-loyalty-local`.
  La **remise remerciement** (lot 3) est encore autre chose : un geste monétaire libre
  (€ ou %), porté comme une vraie réduction WooCommerce (jamais des frais négatifs),
  applicable dans la Vente ou a posteriori sur une commande (avec correction de recette
  automatique si déjà encaissée). Tests `make e2e-discount-local`.

---

## 6. Secrets

Aucun identifiant ne doit être versionné. Les accès de déploiement et les clés d'API vivent dans
`.env.local` (ignoré par git) ; `.env` ne contient que des valeurs par défaut sans secret.
