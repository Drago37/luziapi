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

# Déploiement o2switch en FTPS (renseigner les DEPLOY_FTP_* dans .env / .env.local).
DEPLOY_FTP_PORT   ?= 21
DEPLOY_FTP_VERIFY ?= yes
THEME_SRC := www/wp-content/themes/luziapi/
# Exclusions (globs lftp) : outils de dev, dépôt git, modules.
DEPLOY_EXCLUDES := -X '.git*' -X 'node_modules/' -X 'tools/' -X 'tests-js/' \
	-X '.php-cs-fixer.dist.php' -X '.php-cs-fixer.cache' \
	-X 'phpstan.neon.dist' -X 'README.md' \
	-X 'package.json' -X 'package-lock.json' -X '.~lock.*\#'
# Réglages lftp : FTPS forcé + chiffrement des données, mode passif, timeouts courts.
LFTP_SETTINGS := set ftp:ssl-force true; set ftp:ssl-protect-data true; \
	set ftp:ssl-protect-list true; set ssl:verify-certificate $(DEPLOY_FTP_VERIFY); \
	set ftp:passive-mode true; set net:max-retries 2; set net:timeout 15;
# Mot de passe FTP lu au runtime depuis .env.local (make tronque une valeur contenant un #).
READ_FTP_PASS = sed -n 's/^[[:space:]]*DEPLOY_FTP_PASS=//p' .env.local 2>/dev/null | tr -d '\r' | tail -1

# Exécute une commande dans le dossier du thème, à l'intérieur du conteneur.
IN_THEME = $(DC) exec -T $(WP) bash -lc 'cd $(THEME) && $(1)'

.DEFAULT_GOAL := help
.PHONY: help env up start stop restart down destroy build logs ps install fixtures wait \
        composer composer-prod theme plugins wp-install shell wp db db-reset \
        test cs cs-check stan qa deploy deploy-dry deploy-check e2e-local e2e-clean \
        e2e-tracking-local e2e-vente-local e2e-receipt-local e2e-loyalty-local e2e-exclusion-local e2e-offered-pot-local e2e-orphan-receipt-local e2e-newsletter-local e2e-delivery-zone-local e2e-discount-local e2e-vente-loyalty-local e2e-loyalty-client-local e2e-loyalty-dashboard-local e2e-prod e2e-prod-send \
        e2e-tracking-prod e2e-tracking-prod-send e2e-loyalty-prod e2e-exclusion-prod e2e-offered-pot-prod e2e-orphan-receipt-prod e2e-newsletter-prod e2e-delivery-zone-prod e2e-receipt-prod

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

e2e-local: ## Joue le test e2e des commandes en local (identité : tools/.e2e-identity.json)
	$(DC) run --rm -e LUZIAPI_E2E_PAYLOAD wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orders.php';" --user=admin

e2e-clean: ## Supprime toute trace de commande/produit de test e2e resté en base
	$(DC) run --rm -e LUZIAPI_E2E_PAYLOAD='{"options":{"cleanup_only":true}}' wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orders.php';" --user=admin

e2e-tracking-local: fixtures ## Teste le suivi avec le vrai WordPress/WooCommerce local (aucun e-mail envoyé)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-order-tracking.php';" --user=admin

e2e-vente-local: ## Teste la Vente (préremplissage client + point d'entrée unique) sur le vrai WooCommerce local
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente.php';" --user=admin

backfill-receipts-local: ## Porte au registre l'encaissement des commandes déjà terminées (LUZIAPI_BACKFILL_DRY=1 pour simuler)
	$(DC) run --rm -e LUZIAPI_BACKFILL_DRY wpcli wp eval "require ABSPATH . '$(THEME)/tools/backfill-receipts.php';" --user=admin

backfill-loyalty-local: ## Rétro-crédite les pots des commandes déjà terminées (LUZIAPI_BACKFILL_DRY=1 pour simuler)
	$(DC) run --rm -e LUZIAPI_BACKFILL_DRY wpcli wp eval "require ABSPATH . '$(THEME)/tools/backfill-loyalty.php';" --user=admin

e2e-receipt-local: ## Teste l'enregistrement auto de la recette au passage « Terminée »
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-receipt-on-complete.php';" --user=admin

e2e-loyalty-local: ## Teste l'acquisition de fidélité (crédit/contre-passation des pots) au passage « Terminée »
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-on-complete.php';" --user=admin

e2e-exclusion-local: ## Teste la case « Exclure de la fidélité » (vrai chemin admin : save → action → recalcul)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-exclusion-on-toggle.php';" --user=admin

e2e-offered-pot-local: ## Teste l'ajout d'un pot offert (geste + fidélité) à une commande existante (vrai chemin admin)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-offered-pot-on-save.php';" --user=admin

e2e-orphan-receipt-local: ## Teste le retrait de la recette quand la commande est mise à la corbeille ou supprimée
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orphan-receipt-on-delete.php';" --user=admin

e2e-newsletter-local: ## Teste l'auto-envoi newsletter (planification + envoi Brevo intercepté, aucun e-mail)
	$(DC) run --rm --user root -v "$(CURDIR)/prod-mu-plugins:/mu-src:ro" wpcli cp /mu-src/luziapi-newsletter-autosend.php wp-content/mu-plugins/luziapi-newsletter-autosend.php
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-newsletter-on-publish.php';" --user=admin

e2e-delivery-zone-local: ## Teste la validation de zone de livraison au checkout (rejet hors Bléré/Luzillé 37150)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-delivery-zone-check.php';" --user=admin

e2e-discount-local: ## Teste la remise remerciement (vraie réduction WooCommerce, création et a posteriori)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-thankyou-discount.php';" --user=admin

e2e-vente-loyalty-local: ## Teste le chemin réel de la Vente avec pot offert, fidélité et remise
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente-loyalty.php';" --user=admin

e2e-loyalty-client-local: ## Teste les surfaces client : bloc fidélité dans l'e-mail « Terminée » + correction de recette de la remise a posteriori
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-client.php';" --user=admin

e2e-loyalty-dashboard-local: ## Teste la page Fidélité du pilotage (récap par client + classements)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-dashboard.php';" --user=admin

e2e-prod: ## Test e2e sur la PROD en dry-run (aucun e-mail, dépose→exécute→supprime)
	@bash scripts/e2e-prod.sh

e2e-prod-send: ## Test e2e sur la PROD avec e-mails RÉELS vers l'adresse d'identité
	@bash scripts/e2e-prod.sh --send

e2e-tracking-prod: ## Test e2e du suivi sur la PROD en dry-run (crée+supprime une commande test, aucun e-mail)
	@bash scripts/e2e-tracking-prod.sh

e2e-tracking-prod-send: ## Test e2e du suivi sur la PROD, lien magique RÉEL vers l'adresse d'identité
	@bash scripts/e2e-tracking-prod.sh --send

e2e-loyalty-prod: ## Test e2e de la fidélité sur la PROD (produit+commande de test isolés, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-loyalty-prod.sh

e2e-exclusion-prod: ## Test e2e de la case « Exclure de la fidélité » sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-exclusion-prod.sh

e2e-offered-pot-prod: ## Test e2e de l'ajout d'un pot offert à une commande existante sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-offered-pot-prod.sh

e2e-orphan-receipt-prod: ## Test e2e du retrait des recettes orphelines sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-orphan-receipt-prod.sh

e2e-newsletter-prod: ## Test e2e de l'auto-envoi newsletter sur la PROD (isolé, envoi Brevo intercepté, tout nettoyé)
	@bash scripts/e2e-newsletter-prod.sh

e2e-delivery-zone-prod: ## Test e2e de la validation de zone de livraison sur la PROD (lecture seule, aucun e-mail)
	@bash scripts/e2e-delivery-zone-prod.sh

e2e-receipt-prod: ## Test e2e de la recette auto (« Terminée » → recette) sur la PROD (isolé, tout nettoyé)
	@bash scripts/e2e-receipt-prod.sh

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
test: ## Lance toute la suite PHPUnit
	@composer test

cs: ## Corrige le style du code (PHP-CS-Fixer)
	@$(call IN_THEME,composer cs)

cs-check: ## Vérifie le style sans corriger
	@$(call IN_THEME,composer cs:check)

stan: ## Analyse statique (PHPStan)
	@$(call IN_THEME,composer stan)

qa: cs-check stan ## Lance toutes les vérifications (style + analyse)
	@printf "\033[1;32m✔  Vérifications terminées\033[0m\n"

test-js: ## Lance les tests JavaScript du thème (jsdom)
	@cd www/wp-content/themes/luziapi && npm test --silent

##@ Déploiement (o2switch, FTPS)
deploy-check: ## Vérifie la config FTPS (.env / .env.local) et la présence de lftp
	@command -v lftp >/dev/null || { printf "\033[1;31m✗  lftp manquant\033[0m — installe-le : sudo apt-get install -y lftp\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_HOST)" || { printf "\033[1;31m✗  DEPLOY_FTP_HOST manquant\033[0m (ex: luziapi.fr) — voir .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_USER)" || { printf "\033[1;31m✗  DEPLOY_FTP_USER manquant\033[0m (compte FTP) — voir .env.local\n"; exit 1; }
	@test -n "$$($(READ_FTP_PASS))" || { printf "\033[1;31m✗  DEPLOY_FTP_PASS manquant\033[0m dans .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_PATH)" || { printf "\033[1;31m✗  DEPLOY_FTP_PATH manquant\033[0m (ex: public_html/wp-content/themes/luziapi) — voir .env.local\n"; exit 1; }
	@printf "✔  Cible FTPS : \033[36m%s@%s:%s\033[0m  (port %s, verify-cert=%s)\n" "$(DEPLOY_FTP_USER)" "$(DEPLOY_FTP_HOST)" "$(DEPLOY_FTP_PATH)" "$(DEPLOY_FTP_PORT)" "$(DEPLOY_FTP_VERIFY)"

deploy-dry: deploy-check ## Simulation du déploiement (mirror --dry-run, n'envoie rien)
	@printf "🔍  Simulation FTPS (aucun fichier envoyé)…\n"
	@LFTP_PASSWORD="$$($(READ_FTP_PASS))" lftp -u '$(DEPLOY_FTP_USER)' --env-password -p $(DEPLOY_FTP_PORT) '$(DEPLOY_FTP_HOST)' \
		-e "$(LFTP_SETTINGS) mirror -R --delete --dry-run --verbose $(DEPLOY_EXCLUDES) $(THEME_SRC) $(DEPLOY_FTP_PATH); bye"

deploy: deploy-check composer-prod ## Déploie le thème sur o2switch (FTPS) puis restaure les deps de dev
	@printf "🚀  Déploiement FTPS vers \033[36m%s:%s\033[0m…\n" "$(DEPLOY_FTP_HOST)" "$(DEPLOY_FTP_PATH)"
	@LFTP_PASSWORD="$$($(READ_FTP_PASS))" lftp -u '$(DEPLOY_FTP_USER)' --env-password -p $(DEPLOY_FTP_PORT) '$(DEPLOY_FTP_HOST)' \
		-e "$(LFTP_SETTINGS) mirror -R --delete --verbose $(DEPLOY_EXCLUDES) $(THEME_SRC) $(DEPLOY_FTP_PATH); bye"
	@printf "🔧  Restauration des dépendances de dev en local…\n"
	@$(call IN_THEME,composer install --no-interaction)
	@printf "\n\033[1;32m✔  Thème déployé\033[0m  →  https://luziapi.fr\n\n"
	@bash scripts/post-deploy-check.sh

deploy-check-live: ## Vérifie que la prod répond (URL non cachées) — à lancer après tout déploiement
	@bash scripts/post-deploy-check.sh
