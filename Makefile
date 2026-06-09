# ============================================================================
#  LuziApi — Makefile (raccourcis & mémo)
#  Tape `make` ou `make help` pour voir toutes les commandes.
# ============================================================================

DC     := docker compose
WP     := wordpress
THEME  := wp-content/themes/luziapi
ARGS   ?=

# Charge les variables d'environnement : .env (valeurs par défaut) puis
# .env.local (overrides & secrets, non versionné) qui a la priorité.
-include .env
-include .env.local

# Déploiement o2switch (renseigner les DEPLOY_* dans .env / .env.local — voir .env.example).
DEPLOY_PORT ?= 22
THEME_SRC   := www/wp-content/themes/luziapi/
DEPLOY_EXCLUDES := --exclude='.git' --exclude='node_modules' --exclude='tools' \
	--exclude='.php-cs-fixer.dist.php' --exclude='.php-cs-fixer.cache' \
	--exclude='phpstan.neon.dist' --exclude='README.md'
# Expanse un éventuel ~ en tête de DEPLOY_KEY (non expansé entre guillemets côté shell).
DEPLOY_KEY := $(patsubst ~/%,$(HOME)/%,$(DEPLOY_KEY))
# Commande SSH (ajoute -i <clé> si DEPLOY_KEY est défini) et destination rsync.
DEPLOY_SSH_OPT := ssh -p $(DEPLOY_PORT)$(if $(DEPLOY_KEY), -i $(DEPLOY_KEY))
DEPLOY_DEST    := $(DEPLOY_USER)@$(DEPLOY_HOST):$(DEPLOY_PATH)

# Exécute une commande dans le dossier du thème, à l'intérieur du conteneur.
IN_THEME = $(DC) exec -T $(WP) bash -lc 'cd $(THEME) && $(1)'

.DEFAULT_GOAL := help
.PHONY: help env up start stop restart down destroy build logs ps install fixtures wait \
        composer composer-prod theme plugins wp-install shell wp db db-reset \
        cs cs-check stan qa deploy deploy-dry deploy-check

help: ## Affiche cette aide
	@printf "\n\033[1;33m🐝  LuziApi — commandes disponibles\033[0m\n"
	@printf "    Usage : \033[36mmake <cible>\033[0m   ·   Site : http://localhost:8080   ·   phpMyAdmin : http://localhost:8081\n"
	@awk 'BEGIN {FS = ":.*##"} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5); next} \
		/^[a-zA-Z0-9_.-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\n"

##@ Installation
env: ## Crée .env.local (overrides locaux : secrets, accès o2switch) si absent
	@test -f .env.local && echo "✔  .env.local déjà présent" || { \
		printf '%s\n' \
			'# Overrides locaux — NON versionné. Surcharge .env.' \
			'# Accès o2switch pour `make deploy` (voir .env pour la doc des variables) :' \
			'DEPLOY_HOST=' \
			'DEPLOY_USER=' \
			'#DEPLOY_PORT=22' \
			'DEPLOY_PATH=' \
			'DEPLOY_KEY=' \
		> .env.local && echo "✔  .env.local créé — à compléter pour le déploiement"; }

install: env up wait composer wp-install theme plugins fixtures ## Installe tout (1er lancement complet)
	@printf "\n\033[1;32m✔  Site prêt :\033[0m http://localhost:8080  (admin / admin)\n\n"

fixtures: ## Charge le contenu de démo (4 miels + actualités, devise EUR) — idempotent
	$(DC) run --rm wpcli wp eval-file $(THEME)/tools/fixtures.php --user=admin

wait: ## Attend que le cœur WordPress soit déposé dans www/
	@echo "⏳  Attente de l'installation du cœur WordPress..."
	@for i in $$(seq 1 30); do [ -f www/wp-settings.php ] && exit 0; sleep 2; done; \
		echo "⚠  www/wp-settings.php introuvable — vérifie 'make logs'."

composer: ## Installe les dépendances du thème (Timber + outils dev)
	@$(call IN_THEME,composer install)

composer-prod: ## Dépendances du thème en mode production (sans dev) — pour le déploiement
	@$(call IN_THEME,composer install --no-dev --optimize-autoloader)

wp-install: ## Installe WordPress (admin/admin)
	$(DC) run --rm wpcli wp core install \
		--url="http://localhost:$${WP_PORT:-8080}" \
		--title="LuziApi" \
		--admin_user="admin" --admin_password="admin" \
		--admin_email="anthony@example.com" --skip-email

theme: ## Active le thème LuziApi
	$(DC) run --rm wpcli wp theme activate luziapi

plugins: ## Installe et active WooCommerce + Contact Form 7
	$(DC) run --rm wpcli wp plugin install woocommerce contact-form-7 --activate

db-reset: ## Réinitialise la base et réinstalle WordPress + thème + plugins (⚠ efface le contenu)
	$(DC) run --rm wpcli wp db reset --yes
	@$(MAKE) --no-print-directory wp-install
	@$(MAKE) --no-print-directory theme
	@$(MAKE) --no-print-directory plugins
	@$(MAKE) --no-print-directory fixtures
	@printf "\n\033[1;32m✔  Base réinitialisée — site remis à neuf\033[0m\n\n"

##@ Docker
up: ## Construit et démarre la stack (détaché)
	$(DC) up -d --build

start: ## Démarre les conteneurs (sans rebuild)
	$(DC) start

stop: ## Arrête les conteneurs (sans rien supprimer)
	$(DC) stop

restart: ## Redémarre les conteneurs
	$(DC) restart

down: ## Arrête et supprime les conteneurs (conserve la base)
	$(DC) down

destroy: ## Supprime TOUT, y compris la base de données (⚠ irréversible)
	$(DC) down -v

build: ## Reconstruit l'image WordPress
	$(DC) build

logs: ## Affiche les logs en continu
	$(DC) logs -f

ps: ## Liste l'état des conteneurs
	$(DC) ps

##@ Accès & wp-cli
shell: ## Ouvre un shell dans le conteneur WordPress
	$(DC) exec $(WP) bash

wp: ## Lance une commande wp-cli — ex : make wp ARGS="plugin list"
	$(DC) run --rm wpcli wp $(ARGS)

db: ## Ouvre le client MySQL sur la base
	$(DC) exec db mariadb -u$${DB_USER:-luziapi} -p$${DB_PASSWORD:-luziapi} $${DB_NAME:-luziapi}

##@ Qualité (thème uniquement)
cs: ## Corrige le style du code (PHP-CS-Fixer)
	@$(call IN_THEME,composer cs)

cs-check: ## Vérifie le style sans corriger
	@$(call IN_THEME,composer cs:check)

stan: ## Analyse statique (PHPStan)
	@$(call IN_THEME,composer stan)

qa: cs-check stan ## Lance toutes les vérifications (style + analyse)
	@printf "\033[1;32m✔  Vérifications terminées\033[0m\n"

##@ Déploiement (o2switch)
deploy-check: ## Vérifie que la config de déploiement est renseignée (.env / .env.local)
	@test -n "$(DEPLOY_HOST)" || { printf "\033[1;31m✗  DEPLOY_HOST manquant\033[0m (ex: nodeXXXX.n0c.com) — voir .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_USER)" || { printf "\033[1;31m✗  DEPLOY_USER manquant\033[0m (ton login cPanel) — voir .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_PATH)" || { printf "\033[1;31m✗  DEPLOY_PATH manquant\033[0m (ex: ~/public_html/wp-content/themes/luziapi) — voir .env.local\n"; exit 1; }
	@printf "✔  Cible : \033[36m%s\033[0m  (port %s%s)\n" "$(DEPLOY_DEST)" "$(DEPLOY_PORT)" "$(if $(DEPLOY_KEY), , — aucune clé : mot de passe demandé)"

deploy-dry: deploy-check ## Simulation du déploiement (rsync --dry-run, n'envoie rien)
	@printf "🔍  Simulation (aucun fichier envoyé)…\n"
	@rsync -rltzv --dry-run --delete -e "$(DEPLOY_SSH_OPT)" $(DEPLOY_EXCLUDES) $(THEME_SRC) "$(DEPLOY_DEST)/"

deploy: deploy-check composer-prod ## Déploie le thème sur o2switch (rsync/SSH) puis restaure les deps de dev
	@printf "🚀  Déploiement vers \033[36m%s\033[0m…\n" "$(DEPLOY_DEST)"
	@rsync -rltz --delete -e "$(DEPLOY_SSH_OPT)" $(DEPLOY_EXCLUDES) $(THEME_SRC) "$(DEPLOY_DEST)/"
	@printf "🔧  Restauration des dépendances de dev en local…\n"
	@$(call IN_THEME,composer install --no-interaction)
	@printf "\n\033[1;32m✔  Thème déployé\033[0m  →  https://luziapi.fr\n\n"
