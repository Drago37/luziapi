# Thème LuziApi

Thème WordPress vitrine + boutique pour l'activité apicole **LuziApi** (Anthony Graule, Luzillé 37).
Basé sur **Timber 2 / Twig 3** et **WooCommerce**. Palette miel & bois.

---

## 1. Prérequis

- WordPress 6.5+ (testé pour 7.0)
- PHP 8.2+
- [Composer](https://getcomposer.org/) (pour installer Timber)
- Extensions : **WooCommerce**, **Contact Form 7** (et plus tard l'anti-spam Turnstile)

## 2. Installation du thème

```bash
# Déposer le dossier "luziapi/" dans wp-content/themes/
cd wp-content/themes/luziapi
composer install          # installe Timber dans vendor/
```

Puis dans l'admin WordPress : **Apparence → Thèmes → Activer « LuziApi »**.

> Sur o2switch, Composer est disponible en SSH. Sinon, lancer `composer install` en local et téléverser le dossier `vendor/` avec le thème.

## 3. Réglages WordPress de base

1. **Page d'accueil** : créer une page vide « Accueil », puis **Réglages → Lecture → La page d'accueil affiche → Une page statique → Accueil**. Le thème utilise automatiquement `front-page.php` (la one-page).
2. **Mentions légales** : créer une page intitulée « Mentions légales » avec le **slug `mentions-legales`**. Laissée vide, elle affiche le gabarit pré-rempli (éditeur, SIRET, hébergeur o2switch, RGPD…). Vérifier/ajuster le texte si besoin.
3. **Permaliens** : **Réglages → Permaliens → Nom de l'article** (jolies URLs).

## 4. Configuration WooCommerce

### a) Pas de TVA (franchise en base)
**WooCommerce → Réglages → Général** : décocher **« Activer les taxes »**. Les prix s'affichent sans TVA.
La mention « TVA non applicable, art. 293 B du CGI » figure déjà dans les mentions légales.

### b) Les 4 miels (pots de 1 kg)
Créer 4 produits **simples**, gestion de stock activée (**Stock → Gérer le stock ? oui**) :

| Produit | Prix | Stock initial | État |
|---|---|---|---|
| Miel de Printemps | 10 € | quantité réelle | en vente |
| Miel d'Acacia | 14 € | quantité réelle | en vente |
| Miel de Châtaignier | 12 € | 0 | **À venir** |
| Miel de Tournesol | 11 € | 0 | **À venir** |

Pour chaque produit, dans l'encart **« Options LuziApi »** (colonne de droite) :
- **Sous-titre (étiquette)** : ex. « Récolte de printemps », « Floraison de mai »…
- **« Annoncer comme À venir »** : cocher pour Châtaignier et Tournesol (force l'indisponibilité même sans toucher au stock).

> **Automatique** : dès qu'un stock tombe à **0**, le produit passe en « À venir » (badge + bouton désactivé), sans intervention. La case manuelle sert à l'annoncer avant même la première récolte. Tout est pilotable depuis l'admin, sans code.

Astuce visuel : tant qu'il n'y a pas de photo de pot, le thème affiche un **pot illustré teinté** à la couleur du miel. La couleur est choisie d'après le **slug** du produit (`miel-de-printemps`, `acacia`, `chataignier`, `tournesol`). Ajouter une image produit la remplacera automatiquement.

### c) Remise dès 2 pots
**Automatique** (codée dans le thème) : **−1 € par pot** dès que le panier contient **2 pots ou plus**. Rien à configurer.

### d) Livraison
**WooCommerce → Réglages → Expédition**. Créer deux zones :
- **Zone « Luzillé »** (code postal `37150`) → méthode **« Livraison gratuite »**.
- **Zone « Bléré »** (code postal `37150` Bléré, vérifier le CP exact) → méthode **« Livraison gratuite »**.
- Dans chaque zone, ajouter aussi **« Retrait sur place »** (point de retrait = adresse de l'entreprise).
- **Pas de zone « partout ailleurs »** avec livraison → les clients hors de ces communes ne pourront choisir que le retrait.

Activer le **Retrait sur place** : **Réglages → Expédition → Retrait local** (ou méthode « Local pickup »).

### e) Paiement (v1, sans paiement en ligne)
**WooCommerce → Réglages → Paiements** :
- **« Paiement à la livraison »** → activer et **renommer** « Espèces ou chèque à la remise » (au retrait ou à la livraison).
- **« Virement bancaire (BACS) »** → activer et **renommer** « Virement bancaire / WERO ». Dans les instructions, indiquer l'IBAN / le numéro WERO et préciser « à régler **avant** le retrait ou la livraison ».
- Laisser les paiements par carte **désactivés** (on pourra ajouter Stripe plus tard via l'extension officielle, sans rien casser).

## 5. Formulaire de contact (Contact Form 7)

1. Créer un formulaire dans **Contact → Ajouter** (champs : nom, e-mail, téléphone, message, case consentement RGPD).
2. Récupérer son shortcode, ex. `[contact-form-7 id="123" title="Contact"]`.
3. Le déclarer dans `wp-config.php` :
   ```php
   define('LUZIAPI_CF7', '[contact-form-7 id="123" title="Contact"]');
   ```
   Le formulaire s'affichera alors dans la section Contact à la place du formulaire d'exemple.
4. **E-mails** : sur un hébergement mutualisé, installer une extension **SMTP** (ex. *WP Mail SMTP*) avec un compte d'envoi, sinon les mails risquent de partir en spam.

## 6. Newsletter Brevo (e-mail + SMS)

L'encart « Newsletter » de la page d'accueil affiche le **formulaire Brevo** (e-mail + SMS). Tant qu'aucun shortcode n'est défini, un formulaire d'exemple s'affiche.

**Pourquoi Brevo** : société française, données en UE (RGPD), e-mail gratuit (~300/jour), SMS en option (crédits), et il peut aussi servir de **SMTP** pour les e-mails du site (contact, commandes) → meilleure délivrabilité, un seul outil. (Alternative e-mail seul : MailPoet, `[mailpoet_form id="1"]`.)

### a) Connexion
1. Créer un compte sur [brevo.com](https://www.brevo.com/fr/) (gratuit).
2. Installer l'extension WordPress **Brevo** (ex-Sendinblue) et la connecter avec la **clé API** (Brevo → SMTP & API → Clés API).
3. **SMTP / e-mails du site** : dans l'extension, activer l'envoi des e-mails WordPress via Brevo (remplace WP Mail SMTP — voir §5).

### b) Liste + formulaire d'inscription
4. Créer une **liste** de contacts (ex. « Clients / Newsletter »).
5. Construire un **formulaire d'inscription** Brevo avec : champ **Email**, champ **SMS** (téléphone, au format international, ex. +33…), une **case de consentement e-mail** et une **case de consentement SMS distincte**. Activer le **double opt-in**.
6. Récupérer le shortcode du formulaire, puis le déclarer dans `wp-config.php` :
   ```php
   define('LUZIAPI_NEWSLETTER', '[sibwp_form id=1]'); // remplacer 1 par l'ID du formulaire
   ```
   Le formulaire Brevo remplace alors l'exemple dans l'encart.

### c) Ajouter des contacts soi-même
7. Brevo → **Contacts → Ajouter un contact** (ou **Importer** un CSV). Renseigner l'e-mail et/ou le numéro, et cocher l'appartenance à la liste. Veiller à n'ajouter que des personnes ayant donné leur accord.

### d) Envoyer une info
8. **E-mail** : Brevo → Campagnes → **Email** → choisir la liste → rédiger → envoyer.
9. **SMS** : acheter des **crédits SMS** ; définir l'**expéditeur** (nom alphanumérique, ≤ 11 caractères, ex. `LuziApi`) ; Campagnes → **SMS** → cibler les contacts ayant un numéro **et** le consentement SMS. Brevo ajoute/gère automatiquement le **STOP**.

### e) RGPD / CNIL
- Double opt-in, **consentement SMS explicite et séparé** du consentement e-mail.
- Lien de désinscription dans chaque e-mail (auto) ; **STOP** pour les SMS (auto).
- **SMS** : pas d'envoi le soir, le dimanche ni les jours fériés (reco CNIL) ; coût ~0,05–0,08 €/SMS.
- Mentionner la newsletter dans la politique de confidentialité.

## 7. Anti-spam (à faire plus tard)

Recommandation : **Cloudflare Turnstile** (gratuit, RGPD-friendly) + honeypot.
Extension *Contact Form 7 – Turnstile* ou l'intégration native, plus le module honeypot de CF7.

## 8. Carte de localisation

Les coordonnées du point de retrait sont dans `inc/setup.php` (`wp_localize_script … LUZIAPI_MAP`).
Ajuster `lat` / `lng` si besoin pour pointer précisément l'adresse. La carte utilise Leaflet + OpenStreetMap (sans clé API, sans cookie tiers).

## 9. Photos

Trois photos sont dans `assets/img/` (`hero.jpg`, `apiculteur.jpg`, `savoir-faire.jpg`).
Pour en changer, remplacer les fichiers en gardant les mêmes noms. Crédit © Thomas Bourdilleau (déjà au pied de page).

## 10. Qualité du code (optionnel)

```bash
composer cs:check   # style (PHP-CS-Fixer)
composer stan       # analyse statique (PHPStan + stubs WordPress)
```

---

## Structure

```
luziapi/
├── style.css            en-tête de thème
├── functions.php        bootstrap (Timber + inc/)
├── composer.json        Timber + outils qualité
├── inc/
│   ├── setup.php         supports, menus, styles & scripts
│   ├── timber.php        contexte global Twig (coordonnées…)
│   ├── shop.php          miels, état « À venir », remise 2 pots, réglages admin
│   └── woocommerce.php    habillage des pages boutique
├── templates/           Twig (rendu via Timber uniquement)
│   ├── base.twig
│   ├── front-page.twig   la one-page (toutes les sections)
│   ├── page.twig
│   ├── page-mentions-legales.twig
│   ├── woocommerce.twig
│   ├── index.twig
│   └── partials/{header,footer}.twig
├── assets/{css,js,img}
├── front-page.php · page.php · index.php · woocommerce.php   (contrôleurs)
└── README.md
```
