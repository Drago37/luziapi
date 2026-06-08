# ============================================================================
#  LuziApi — Makefile (raccourcis & mémo)
#  Tape `make` ou `make help` pour voir toutes les commandes.
# ============================================================================

DC     := docker compose
WP     := wordpress
THEME  := wp-content/themes/luziapi
ARGS   ?=

# Exécute une commande dans le dossier du thème, à l'intérieur du conteneur.
IN_THEME = $(DC) exec -T $(WP) bash -lc 'cd $(THEME) && $(1)'

.DEFAULT_GOAL := help
.PHONY: help env up start stop restart down destroy build logs ps install wait \
        composer composer-prod theme plugins wp-install shell wp db db-reset \
        cs cs-check stan qa

help: ## Affiche cette aide
	@printf "\n\033[1;33m🐝  LuziApi — commandes disponibles\033[0m\n"
	@printf "    Usage : \033[36mmake <cible>\033[0m   ·   Site : http://localhost:8080   ·   phpMyAdmin : http://localhost:8081\n"
	@awk 'BEGIN {FS = ":.*##"} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5); next} \
		/^[a-zA-Z0-9_.-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\n"

##@ Installation
env: ## Crée le fichier .env depuis .env.example (si absent)
	@test -f .env || (cp .env.example .env && echo "✔  .env créé")

install: env up wait composer wp-install theme plugins ## Installe tout (1er lancement complet)
	@printf "\n\033[1;32m✔  Site prêt :\033[0m http://localhost:8080  (admin / admin)\n\n"

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
