# LuziApi — site vitrine + boutique

Dépôt du site **luziapi.fr** (apiculture, Anthony Graule — Luzillé 37).
WordPress + WooCommerce, thème custom **Timber/Twig**, environnement de développement **Docker**.

```
luziapi/
├── www/                         racine WordPress (document root)
│   └── wp-content/
│       ├── themes/luziapi/      ← LE THÈME (seul code versionné)
│       ├── plugins/             (non versionné)
│       └── uploads/             (non versionné)
├── docker/                      Dockerfile + réglages PHP
├── docker-compose.yml           WordPress + MariaDB + phpMyAdmin + wp-cli
├── .env                         valeurs par défaut (versionné) ; overrides → .env.local
├── .github/workflows/ci.yml     PHPStan + CS-Fixer (thème uniquement)
├── DEPLOIEMENT.md               passage du local au serveur o2switch
└── README.md
```

> **À noter** : on ne versionne **que le thème**. Le cœur de WordPress, les plugins et les médias ne sont **pas** dans le dépôt (bonne pratique). En local, le cœur de WordPress est déposé automatiquement par Docker dans `www/` au premier démarrage ; sur le serveur, il est fourni par l'hébergeur.

---

## Prérequis

- [Docker](https://www.docker.com/) + Docker Compose

## Démarrage en local

> 💡 Le plus simple : un **Makefile** regroupe toutes les commandes. Tape `make` (ou `make help`) pour le mémo complet.
>
> ```bash
> make install      # tout en une fois : .env, Docker, Timber, WordPress, thème, plugins
> ```
>
> Puis va sur http://localhost:8080 (admin / admin). Les commandes ci-dessous sont l'équivalent « manuel ».

```bash
# 1. Configuration : .env est déjà versionné (valeurs par défaut).
#    Pour des réglages locaux ou les accès de déploiement, créer .env.local :
make env   # crée .env.local si absent (sinon, rien à faire)

# 2. Démarrer la stack (construit l'image, lance WordPress + base + phpMyAdmin)
docker compose up -d --build
#    → au 1er lancement, WordPress (core) est installé dans ./www

# 3. Installer Timber dans le thème (dépendances Composer)
docker compose exec wordpress bash -c "cd wp-content/themes/luziapi && composer install"

# 4. Installer WordPress (en ligne de commande)
docker compose run --rm wpcli wp core install \
  --url="http://localhost:8080" \
  --title="LuziApi" \
  --admin_user="admin" --admin_password="admin" \
  --admin_email="anthony@example.com"

# 5. Activer le thème + installer les extensions
docker compose run --rm wpcli wp theme activate luziapi
docker compose run --rm wpcli wp plugin install woocommerce contact-form-7 --activate
```

Accès :

| Service | URL |
|---|---|
| Site | http://localhost:8080 |
| Admin WordPress | http://localhost:8080/wp-admin (admin / admin) |
| phpMyAdmin | http://localhost:8081 |

Ensuite, suivre la configuration boutique dans **`www/wp-content/themes/luziapi/README.md`** (TVA désactivée, 4 miels, zones de livraison Luzillé/Bléré, paiements espèces/chèque + virement/WERO, Contact Form 7…).

Arrêter / relancer :

```bash
docker compose stop        # arrête sans perdre les données
docker compose up -d       # relance
docker compose down -v     # SUPPRIME tout (y compris la base)
```

## wp-cli

Toutes les commandes WordPress passent par le service `wpcli` :

```bash
docker compose run --rm wpcli wp <commande>
# ex. : docker compose run --rm wpcli wp option get home
```

## Qualité du code (thème)

```bash
cd www/wp-content/themes/luziapi
composer install
composer cs       # corrige le style (PHP-CS-Fixer)
composer cs:check # vérifie sans corriger
composer stan     # analyse statique (PHPStan + stubs WP/WooCommerce)
```

La CI GitHub Actions lance `cs:check` + `stan` à chaque push touchant le thème (voir `.github/workflows/ci.yml`).
Si la CI signale du style, lancer `composer cs` en local pour corriger automatiquement.

## Mise en ligne

Voir **[DEPLOIEMENT.md](DEPLOIEMENT.md)** pour la procédure complète vers o2switch.
