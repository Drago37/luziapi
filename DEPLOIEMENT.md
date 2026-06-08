# Mise en ligne — du local vers o2switch

Ce guide décrit le passage du site local (Docker) vers l'hébergement **o2switch**, sur le domaine **luziapi.fr**.

Deux approches possibles :
- **A. Site neuf sur le serveur** (recommandé pour une première mise en ligne) : on installe WordPress propre sur o2switch, on y met le thème, on recrée le contenu (produits, pages). Simple et sans surprise.
- **B. Migration complète** du local : on transfère la base + les fichiers. Utile si tu as déjà saisi tout le contenu en local.

---

## Pré-requis communs

- Domaine **luziapi.fr** pointant vers o2switch (zone DNS gérée chez o2switch ou redirigée vers).
- Accès cPanel o2switch + accès **SSH** (utile pour Composer/wp-cli).
- Le thème prêt, avec ses dépendances : sur ta machine,
  ```bash
  cd www/wp-content/themes/luziapi
  composer install --no-dev --optimize-autoloader
  ```
  Le dossier `vendor/` (Timber) doit partir **avec** le thème vers le serveur.

---

## A. Première mise en ligne (site neuf) — recommandé

1. **Installer WordPress** sur o2switch
   - Via l'installeur en 1 clic du cPanel (Softaculous → WordPress), sur le domaine `luziapi.fr`,
   - ou manuellement (télécharger WordPress, créer une base MySQL dans cPanel, remplir `wp-config.php`).

2. **Activer le HTTPS** : dans cPanel, activer le certificat **Let's Encrypt** pour `luziapi.fr` (gratuit). Forcer le HTTPS.

3. **Téléverser le thème**
   - Copier le dossier `www/wp-content/themes/luziapi/` (avec son `vendor/`) dans `wp-content/themes/` du serveur (via SFTP ou le gestionnaire de fichiers cPanel).
   - Dans **Apparence → Thèmes**, activer **LuziApi**.

4. **Installer les extensions** : WooCommerce et Contact Form 7 (depuis l'admin → Extensions), plus une extension **SMTP** (ex. WP Mail SMTP) pour la fiabilité des e-mails.

5. **Recréer le contenu** en suivant `www/wp-content/themes/luziapi/README.md` :
   - page d'accueil statique + page **Mentions légales** (slug `mentions-legales`),
   - les 4 miels (Printemps 10 € / Acacia 14 € / Châtaignier 12 € « À venir » / Tournesol 11 € « À venir »),
   - TVA désactivée, zones de livraison **Luzillé** + **Bléré** (gratuites) + retrait,
   - paiements **espèces/chèque à la remise** + **virement/WERO**,
   - formulaire Contact Form 7 (+ `define('LUZIAPI_CF7', '…')` dans `wp-config.php`).

6. **Permaliens** : Réglages → Permaliens → « Nom de l'article ».

7. **Vérifications finales** : pages s'affichent, carte OK, formulaire envoie bien un e-mail (tester), tunnel de commande complet avec une commande test.

---

## B. Migration complète (base + fichiers) depuis le local

1. **Exporter la base locale**
   ```bash
   docker compose exec db mariadb-dump -uluziapi -pluziapi luziapi > luziapi.sql
   ```
   (ou via phpMyAdmin local → Exporter).

2. **Remplacer les URLs** locales par celles de production. Le plus sûr, avec wp-cli (en local, avant export, ou sur le serveur après import) :
   ```bash
   wp search-replace 'http://localhost:8080' 'https://luziapi.fr' --all-tables --precise
   ```
   (sinon, extension **Better Search Replace** dans l'admin).

3. **Créer la base sur o2switch** (cPanel → MySQL), noter nom/utilisateur/mot de passe.

4. **Importer le dump** (phpMyAdmin du serveur ou `mysql` en SSH).

5. **Téléverser les fichiers** vers le serveur :
   - `wp-content/themes/luziapi/` (avec `vendor/`),
   - `wp-content/uploads/` (les médias),
   - les **extensions** utilisées (`wp-content/plugins/woocommerce`, `contact-form-7`, SMTP…).
   - **Ne pas** téléverser : `wp-config.php` local, `.env`, le dossier `docker/`, `node_modules`.

6. **Configurer `wp-config.php`** côté serveur :
   - identifiants de la base o2switch,
   - **nouvelles clés de sécurité** (générer sur https://api.wordpress.org/secret-key/1.1/salt/),
   - retirer le debug : `define('WP_DEBUG', false);`,
   - définir si besoin `define('WP_HOME','https://luziapi.fr'); define('WP_SITEURL','https://luziapi.fr');`.

7. **HTTPS** (Let's Encrypt), **permaliens** à ré-enregistrer, puis **vérifications finales** (idem section A point 7).

---

## Après la mise en ligne

- **Sauvegardes** : activer les sauvegardes automatiques (o2switch en propose) ou une extension de backup.
- **E-mails** : confirmer que les mails de contact et de commande arrivent bien (sinon, régler le SMTP).
- **Mentions légales** : vérifier le bloc hébergeur (forme juridique o2switch à recopier depuis leur site) et l'éditeur.
- **Mises à jour** : tenir WordPress, WooCommerce et les extensions à jour.
- **Paiement en ligne (plus tard)** : si tu veux ajouter la carte bancaire, installer l'extension Stripe officielle et coller tes clés — rien d'autre à changer.

## Mettre à jour le thème ensuite

Le thème est versionné sur GitHub. Pour livrer une évolution :
```bash
cd www/wp-content/themes/luziapi
composer install --no-dev --optimize-autoloader   # si dépendances modifiées
```
puis téléverser le dossier `luziapi/` mis à jour sur le serveur (SFTP), ou tirer la nouvelle version via Git si tu déploies par Git.
