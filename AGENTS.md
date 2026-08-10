# Instructions pour les agents (LuziApi)

Ce fichier est la mémoire de travail du dépôt : il rassemble les règles de collaboration et
les connaissances sur le projet qui ne se déduisent pas du code. Il est destiné à être lu par
n'importe quel assistant de code (Codex, Claude Code, etc.).

> La documentation du dépôt est en **français** (README, DEPLOIEMENT, historique git) ; garder
> cette langue pour les commits, la doc et les échanges. Le **code** reste en anglais.

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
- **DEPLOIEMENT.md** — mise en ligne et mise à jour du thème (`make deploy`, FTPS).
- **docs/prod-o2switch.md** — état de la prod, architecture fonctionnelle, pièges serveur.
  À lire avant toute intervention touchant la production.
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

Tests : `phpunit.xml.dist` + `tests/`. CI : PHPStan + CS-Fixer sur le thème
(`.github/workflows/ci.yml`).

---

## 3. Déploiement — choisir la bonne méthode

### Quelques fichiers du thème changent, sans nouvelle dépendance Composer

→ **Upload FTPS ciblé**, pas `make deploy`.

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

→ `make deploy` reste le bon outil : il faut régénérer `vendor/` en version prod. Démarrer Docker
(`make up`), ou faire `composer install --no-dev` en local avant le mirror puis restaurer les
dépendances de dev (`composer install`).

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
- **Cache PowerBoost** (o2switch) sert parfois une page périmée.
- **Cache navigateur CSS/JS** : le thème enqueue `main.css` / `main.js` avec `filemtime()` comme
  `?ver` (et non plus `LUZIAPI_VERSION`, figé à `1.0.0`). C'était la cause de changements de style
  invisibles. Quand un changement CSS « ne s'affiche pas », vérifier le `?ver` réellement servi.

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

---

## 6. Secrets

Aucun identifiant ne doit être versionné. Les accès de déploiement et les clés d'API vivent dans
`.env.local` (ignoré par git) ; `.env` ne contient que des valeurs par défaut sans secret.
