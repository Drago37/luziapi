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
	-X 'tests-browser/' -X 'playwright.config.js' \
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
.PHONY: help
help: ## Affiche cette aide
	@printf "\n\033[1;33m🐝  LuziApi — commandes disponibles\033[0m\n"
	@printf "    Usage : \033[36mmake <cible>\033[0m   ·   Site : http://localhost:8080   ·   phpMyAdmin : http://localhost:8081\n"
	@awk 'BEGIN {FS = ":.*##"} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5); next} \
		/^[a-zA-Z0-9_.-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\n"

##@ Installation
.PHONY: env
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

.PHONY: install
install: env up wait composer wp-install theme plugins fixtures ## Installe tout (1er lancement complet)
	@printf "\n\033[1;32m✔  Site prêt :\033[0m http://localhost:8080  (admin / admin)\n\n"

.PHONY: fixtures
fixtures: ## Charge le contenu de démo (4 miels + actualités, devise EUR) — idempotent
	$(DC) run --rm wpcli wp eval-file $(THEME)/tools/fixtures.php --user=admin

.PHONY: e2e-local
e2e-local: ## Joue le test e2e des commandes en local (identité : tools/.e2e-identity.json)
	$(DC) run --rm -e LUZIAPI_E2E_PAYLOAD wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orders.php';" --user=admin

.PHONY: e2e-clean
e2e-clean: ## Supprime toute trace de commande/produit de test e2e resté en base
	$(DC) run --rm -e LUZIAPI_E2E_PAYLOAD='{"options":{"cleanup_only":true}}' wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orders.php';" --user=admin

.PHONY: e2e-tracking-local
e2e-tracking-local: fixtures ## Teste le suivi avec le vrai WordPress/WooCommerce local (aucun e-mail envoyé)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-order-tracking.php';" --user=admin

.PHONY: e2e-vente-local
e2e-vente-local: ## Teste la Vente (préremplissage client + point d'entrée unique) sur le vrai WooCommerce local
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente.php';" --user=admin

.PHONY: backfill-receipts-local
backfill-receipts-local: ## Porte au registre l'encaissement des commandes déjà terminées (LUZIAPI_BACKFILL_DRY=1 pour simuler)
	$(DC) run --rm -e LUZIAPI_BACKFILL_DRY wpcli wp eval "require ABSPATH . '$(THEME)/tools/backfill-receipts.php';" --user=admin

.PHONY: backfill-loyalty-local
backfill-loyalty-local: ## Rétro-crédite les pots des commandes déjà terminées (LUZIAPI_BACKFILL_DRY=1 pour simuler)
	$(DC) run --rm -e LUZIAPI_BACKFILL_DRY wpcli wp eval "require ABSPATH . '$(THEME)/tools/backfill-loyalty.php';" --user=admin

.PHONY: backfill-loyalty-prod
backfill-loyalty-prod: ## Rétro-crédite les pots des commandes terminées sur la PROD (SIMULATION par défaut ; APPLY=1 pour écrire)
	@LUZIAPI_BACKFILL_APPLY=$(APPLY) LUZIAPI_BACKFILL_DETAIL=$(DETAIL) bash scripts/backfill-loyalty-prod.sh

.PHONY: audit-receipts-local
audit-receipts-local: ## Audite la dérive recette↔commandes en local (LUZIAPI_AUDIT_YEAR=2026 pour une année)
	$(DC) run --rm -e LUZIAPI_AUDIT_YEAR wpcli wp eval "require ABSPATH . '$(THEME)/tools/audit-receipt-drift.php';" --user=admin

.PHONY: audit-receipts-prod
audit-receipts-prod: ## Audite la dérive recette↔commandes sur la PROD (lecture seule, LUZIAPI_AUDIT_YEAR=2026 pour une année)
	@bash scripts/audit-receipts-prod.sh

.PHONY: audit-loyalty-local
audit-loyalty-local: ## Audite la dérive fidélité en local (trous de crédit + orphelins ; LUZIAPI_AUDIT_YEAR=2026 pour une année)
	$(DC) run --rm -e LUZIAPI_AUDIT_YEAR wpcli wp eval "require ABSPATH . '$(THEME)/tools/audit-loyalty-drift.php';" --user=admin

.PHONY: audit-loyalty-prod
audit-loyalty-prod: ## Audite la dérive fidélité sur la PROD (lecture seule, LUZIAPI_AUDIT_YEAR=2026 pour une année)
	@bash scripts/audit-loyalty-prod.sh

.PHONY: audit-vente-volume-local
audit-vente-volume-local: ## Audite les commandes Vente sans remise de volume en local (LUZIAPI_AUDIT_YEAR=2026 pour une année)
	$(DC) run --rm -e LUZIAPI_AUDIT_YEAR wpcli wp eval "require ABSPATH . '$(THEME)/tools/audit-vente-volume.php';" --user=admin

.PHONY: audit-vente-volume-prod
audit-vente-volume-prod: ## Audite les commandes Vente sans remise de volume sur la PROD (lecture seule, LUZIAPI_AUDIT_YEAR=2026 pour une année)
	@bash scripts/audit-vente-volume-prod.sh

.PHONY: loyalty-inspect-prod
loyalty-inspect-prod: ## Inspecte une commande côté fidélité sur la PROD (lecture seule) — ex : make loyalty-inspect-prod ORDER=106
	@LUZIAPI_ORDER=$(ORDER) bash scripts/loyalty-inspect-prod.sh

.PHONY: loyalty-customer-inspect-local
loyalty-customer-inspect-local: ## Inspecte la fidélité d'un client en local (lecture seule) — ex : make loyalty-customer-inspect-local Q="gaultier"
	$(DC) run --rm -e LUZIAPI_INSPECT_QUERY="$(Q)" wpcli wp eval "require ABSPATH . '$(THEME)/tools/loyalty-customer-inspect.php';" --user=admin

.PHONY: loyalty-customer-inspect-prod
loyalty-customer-inspect-prod: ## Inspecte la fidélité d'un client sur la PROD (lecture seule) — ex : make loyalty-customer-inspect-prod Q="gaultier"
	@LUZIAPI_INSPECT_QUERY="$(Q)" bash scripts/loyalty-customer-inspect-prod.sh

.PHONY: reset-test-loyalty-local
reset-test-loyalty-local: ## Purge l'isolation fidélité des e2e en local (DRY-RUN ; APPLY=1 pour supprimer)
	$(DC) run --rm -e LUZIAPI_RESET_APPLY="$(APPLY)" wpcli wp eval "require ABSPATH . '$(THEME)/tools/reset-test-loyalty.php';" --user=admin

.PHONY: reset-test-loyalty-prod
reset-test-loyalty-prod: ## Rapporte le cluster d'identité des tests fidélité sur la PROD (DRY-RUN, lecture seule)
	@bash scripts/reset-test-loyalty-prod.sh

.PHONY: reset-test-loyalty-prod-apply
reset-test-loyalty-prod-apply: ## Purge le cluster d'identité des tests fidélité sur la PROD (liens + journal résiduel)
	@bash scripts/reset-test-loyalty-prod.sh --apply

.PHONY: directory-inspect-prod
directory-inspect-prod: ## Inspecte les listes Brevo + les groupes du répertoire client sur la PROD (lecture seule)
	@bash scripts/directory-inspect-prod.sh

.PHONY: e2e-receipt-local
e2e-receipt-local: ## Teste l'enregistrement auto de la recette au passage « Terminée »
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-receipt-on-complete.php';" --user=admin

.PHONY: e2e-loyalty-local
e2e-loyalty-local: ## Teste l'acquisition de fidélité (crédit/contre-passation des pots) au passage « Terminée »
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-on-complete.php';" --user=admin

.PHONY: e2e-backfill-loyalty-local
e2e-backfill-loyalty-local: ## Teste le backfill de fidélité (rétro-crédit des commandes déjà terminées, simulation puis réel, idempotence)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-backfill-loyalty.php';" --user=admin

.PHONY: e2e-audit-loyalty-local
e2e-audit-loyalty-local: ## Teste l'audit de dérive fidélité (trou de crédit détecté, commande créditée saine, orphelin après suppression)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-audit-loyalty.php';" --user=admin

.PHONY: e2e-identity-links-local
e2e-identity-links-local: ## Teste les liens d'identité fidélité (auto-lien e-mail/téléphone, fusion manuelle, agrégation)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-identity-links.php';" --user=admin

.PHONY: e2e-merge-admin-local
e2e-merge-admin-local: ## Teste le chemin admin de fusion/défusion de clients fidélité (vrai CustomersController)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-merge-admin.php';" --user=admin

.PHONY: e2e-vente-volume-local
e2e-vente-volume-local: ## Teste la remise de volume (−1 €/pot dès 2 pots) dans la Vente (plusieurs miels)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente-volume.php';" --user=admin

.PHONY: e2e-cart-volume-local
e2e-cart-volume-local: ## Teste la remise de volume au PANIER du site (fee woocommerce_cart_calculate_fees)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-cart-volume.php';" --user=admin

.PHONY: e2e-vente-volume-rattrapage-local
e2e-vente-volume-rattrapage-local: ## Teste le rattrapage de la remise de volume sur une commande (vrai chemin admin + recette + audit)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente-volume-rattrapage.php';" --user=admin

.PHONY: e2e-exclusion-local
e2e-exclusion-local: ## Teste la case « Exclure de la fidélité » (vrai chemin admin : save → action → recalcul)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-exclusion-on-toggle.php';" --user=admin

.PHONY: e2e-offered-pot-local
e2e-offered-pot-local: ## Teste l'ajout d'un pot offert (geste + fidélité) à une commande existante (vrai chemin admin)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-offered-pot-on-save.php';" --user=admin

.PHONY: e2e-orphan-receipt-local
e2e-orphan-receipt-local: ## Teste le retrait de la recette quand la commande est mise à la corbeille ou supprimée
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-orphan-receipt-on-delete.php';" --user=admin

.PHONY: e2e-subscribers-local
e2e-subscribers-local: ## Teste le répertoire d'abonnés Brevo (lecture seule, appels Brevo interceptés)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-subscribers-directory.php';" --user=admin

.PHONY: e2e-customer-profile-local
e2e-customer-profile-local: ## Teste la fiche client dédiée (dépôt réel + schéma + surcharge d'affichage), tout nettoyé
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-customer-profile-local.php';" --user=admin

.PHONY: e2e-pilotage-schema-local
e2e-pilotage-schema-local: ## Teste le schéma du pilotage (migration idempotente, nullabilité, contraintes uniques, sauvegarde/restauration) — copies temporaires, rien de réel touché
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-pilotage-schema-local.php';" --user=admin

.PHONY: e2e-pilotage-perf-local
e2e-pilotage-perf-local: ## Teste la performance du registre des recettes sur un historique volumineux (20 000 lignes) — copie temporaire, index vérifié, rien de réel touché
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-pilotage-perf-local.php';" --user=admin

.PHONY: browser-local
browser-local: ## Smoke de navigation du pilotage (Playwright headless, sur l'hôte) contre le WP local — connexion admin puis chargement de toutes les vues
	cd www/$(THEME) && npm install --no-audit --no-fund && npx playwright install chromium && npm run test:browser

.PHONY: doctor
doctor: ## Répare l'environnement LOCAL si l'admin a perdu ses droits (403 sur /wp-admin/) : rôles standards + capacités WooCommerce
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/local-doctor.php';" --user=admin

.PHONY: e2e-address-lookup-local
e2e-address-lookup-local: ## Teste l'autocomplétion d'adresse (BAN, appels interceptés, endpoint câblé), rien d'écrit
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-address-lookup-local.php';" --user=admin

.PHONY: e2e-subscription-write-local
e2e-subscription-write-local: ## Teste l'écriture d'abonnement Brevo (inscription/désinscription, appels interceptés), aucun contact touché
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-subscription-write-local.php';" --user=admin

.PHONY: e2e-newsletter-local
e2e-newsletter-local: ## Teste l'auto-envoi newsletter (planification + envoi Brevo intercepté, aucun e-mail)
	$(DC) run --rm --user root -v "$(CURDIR)/prod-mu-plugins:/mu-src:ro" wpcli sh -c 'mkdir -p wp-content/mu-plugins && cp /mu-src/luziapi-newsletter-autosend.php wp-content/mu-plugins/luziapi-newsletter-autosend.php'
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-newsletter-on-publish.php';" --user=admin

.PHONY: e2e-delivery-zone-local
e2e-delivery-zone-local: ## Teste la validation de zone de livraison au checkout (rejet hors Bléré/Luzillé 37150)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-delivery-zone-check.php';" --user=admin

.PHONY: e2e-discount-local
e2e-discount-local: ## Teste la remise remerciement (vraie réduction WooCommerce, création et a posteriori)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-thankyou-discount.php';" --user=admin

.PHONY: e2e-vente-loyalty-local
e2e-vente-loyalty-local: ## Teste le chemin réel de la Vente avec pot offert, fidélité et remise
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-vente-loyalty.php';" --user=admin

.PHONY: e2e-loyalty-client-local
e2e-loyalty-client-local: ## Teste les surfaces client : bloc fidélité dans l'e-mail « Terminée » + correction de recette de la remise a posteriori
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-client.php';" --user=admin

.PHONY: e2e-loyalty-dashboard-local
e2e-loyalty-dashboard-local: ## Teste la page Fidélité du pilotage (récap par client + classements)
	$(DC) run --rm wpcli wp eval "require ABSPATH . '$(THEME)/tools/e2e-loyalty-dashboard.php';" --user=admin

.PHONY: e2e-prod
e2e-prod: ## Test e2e sur la PROD en dry-run (aucun e-mail, dépose→exécute→supprime)
	@bash scripts/e2e-prod.sh

.PHONY: e2e-prod-send
e2e-prod-send: ## Test e2e sur la PROD avec e-mails RÉELS vers l'adresse d'identité
	@bash scripts/e2e-prod.sh --send

.PHONY: e2e-tracking-prod
e2e-tracking-prod: ## Test e2e du suivi sur la PROD en dry-run (crée+supprime une commande test, aucun e-mail)
	@bash scripts/e2e-tracking-prod.sh

.PHONY: e2e-tracking-prod-send
e2e-tracking-prod-send: ## Test e2e du suivi sur la PROD, lien magique RÉEL vers l'adresse d'identité
	@bash scripts/e2e-tracking-prod.sh --send

.PHONY: e2e-loyalty-prod
e2e-loyalty-prod: ## Test e2e de la fidélité sur la PROD (produit+commande de test isolés, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-loyalty-prod.sh

.PHONY: e2e-exclusion-prod
e2e-exclusion-prod: ## Test e2e de la case « Exclure de la fidélité » sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-exclusion-prod.sh

.PHONY: e2e-offered-pot-prod
e2e-offered-pot-prod: ## Test e2e de l'ajout d'un pot offert à une commande existante sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-offered-pot-prod.sh

.PHONY: e2e-vente-volume-rattrapage-prod
e2e-vente-volume-rattrapage-prod: ## Test e2e du rattrapage de la remise de volume sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-vente-volume-rattrapage-prod.sh

.PHONY: e2e-orphan-receipt-prod
e2e-orphan-receipt-prod: ## Test e2e du retrait des recettes orphelines sur la PROD (isolé, aucun e-mail, tout nettoyé)
	@bash scripts/e2e-orphan-receipt-prod.sh

.PHONY: e2e-subscribers-prod
e2e-subscribers-prod: ## Test e2e du répertoire d'abonnés Brevo sur la PROD (lecture seule, appels Brevo interceptés)
	@bash scripts/e2e-subscribers-prod.sh

.PHONY: e2e-customer-profile-prod
e2e-customer-profile-prod: ## Test e2e de la fiche client dédiée sur la PROD (commande + fiche de test isolées, tout nettoyé)
	@bash scripts/e2e-customer-profile-prod.sh

.PHONY: e2e-address-lookup-prod
e2e-address-lookup-prod: ## Test e2e de l'autocomplétion d'adresse (BAN) sur la PROD (appels interceptés, rien d'écrit)
	@bash scripts/e2e-address-lookup-prod.sh

.PHONY: e2e-subscription-write-prod
e2e-subscription-write-prod: ## Test e2e de l'écriture d'abonnement Brevo sur la PROD (appels interceptés, aucun contact touché)
	@bash scripts/e2e-subscription-write-prod.sh

.PHONY: e2e-newsletter-prod
e2e-newsletter-prod: ## Test e2e de l'auto-envoi newsletter sur la PROD (isolé, envoi Brevo intercepté, tout nettoyé)
	@bash scripts/e2e-newsletter-prod.sh

.PHONY: e2e-delivery-zone-prod
e2e-delivery-zone-prod: ## Test e2e de la validation de zone de livraison sur la PROD (lecture seule, aucun e-mail)
	@bash scripts/e2e-delivery-zone-prod.sh

.PHONY: e2e-receipt-prod
e2e-receipt-prod: ## Test e2e de la recette auto (« Terminée » → recette) sur la PROD (isolé, tout nettoyé)
	@bash scripts/e2e-receipt-prod.sh

.PHONY: wait
wait: ## Attend que le cœur WordPress soit déposé dans www/
	@echo "⏳  Attente de l'installation du cœur WordPress..."
	@for i in $$(seq 1 30); do [ -f www/wp-settings.php ] && exit 0; sleep 2; done; \
		echo "⚠  www/wp-settings.php introuvable — vérifie 'make logs'."

.PHONY: composer
composer: ## Installe les dépendances du thème (Timber + outils dev)
	@$(call IN_THEME,composer install)

.PHONY: composer-prod
composer-prod: ## Dépendances du thème en mode production (sans dev) — pour le déploiement
	@$(call IN_THEME,composer install --no-dev --optimize-autoloader)

.PHONY: wp-install
wp-install: ## Installe WordPress (admin/admin)
	$(DC) run --rm wpcli wp core install \
		--url="http://localhost:$${WP_PORT:-8080}" \
		--title="LuziApi" \
		--admin_user="admin" --admin_password="admin" \
		--admin_email="anthony@example.com" --skip-email

.PHONY: theme
theme: ## Active le thème LuziApi
	$(DC) run --rm wpcli wp theme activate luziapi

.PHONY: plugins
plugins: ## Installe et active WooCommerce + Contact Form 7
	$(DC) run --rm wpcli wp plugin install woocommerce contact-form-7 --activate

.PHONY: db-reset
db-reset: ## Réinitialise la base et réinstalle WordPress + thème + plugins (⚠ efface le contenu)
	$(DC) run --rm wpcli wp db reset --yes
	@$(MAKE) --no-print-directory wp-install
	@$(MAKE) --no-print-directory theme
	@$(MAKE) --no-print-directory plugins
	@$(MAKE) --no-print-directory fixtures
	@printf "\n\033[1;32m✔  Base réinitialisée — site remis à neuf\033[0m\n\n"

##@ Docker
.PHONY: up
up: ## Construit et démarre la stack (détaché)
	$(DC) up -d --build

.PHONY: start
start: ## Démarre les conteneurs (sans rebuild)
	$(DC) start

.PHONY: stop
stop: ## Arrête les conteneurs (sans rien supprimer)
	$(DC) stop

.PHONY: restart
restart: ## Redémarre les conteneurs
	$(DC) restart

.PHONY: down
down: ## Arrête et supprime les conteneurs (conserve la base)
	$(DC) down

.PHONY: destroy
destroy: ## Supprime TOUT, y compris la base de données (⚠ irréversible)
	$(DC) down -v

.PHONY: build
build: ## Reconstruit l'image WordPress
	$(DC) build

.PHONY: logs
logs: ## Affiche les logs en continu
	$(DC) logs -f

.PHONY: ps
ps: ## Liste l'état des conteneurs
	$(DC) ps

##@ Accès & wp-cli
.PHONY: shell
shell: ## Ouvre un shell dans le conteneur WordPress
	$(DC) exec $(WP) bash

.PHONY: wp
wp: ## Lance une commande wp-cli — ex : make wp ARGS="plugin list"
	$(DC) run --rm wpcli wp $(ARGS)

.PHONY: db
db: ## Ouvre le client MySQL sur la base
	$(DC) exec db mariadb -u$${DB_USER:-luziapi} -p$${DB_PASSWORD:-luziapi} $${DB_NAME:-luziapi}

##@ Qualité (thème uniquement)
.PHONY: test
test: ## Lance toute la suite PHPUnit
	@composer test

.PHONY: cs
cs: ## Corrige le style du code (PHP-CS-Fixer)
	@$(call IN_THEME,composer cs)

.PHONY: cs-check
cs-check: ## Vérifie le style sans corriger
	@$(call IN_THEME,composer cs:check)

.PHONY: stan
stan: ## Analyse statique (PHPStan)
	@$(call IN_THEME,composer stan)

.PHONY: qa
qa: cs-check stan ## Lance toutes les vérifications (style + analyse)
	@printf "\033[1;32m✔  Vérifications terminées\033[0m\n"

.PHONY: test-js
test-js: ## Lance les tests JavaScript du thème (jsdom)
	@cd www/wp-content/themes/luziapi && npm test --silent

##@ Déploiement (o2switch, FTPS)
.PHONY: deploy-check
deploy-check: ## Vérifie la config FTPS (.env / .env.local) et la présence de lftp
	@command -v lftp >/dev/null || { printf "\033[1;31m✗  lftp manquant\033[0m — installe-le : sudo apt-get install -y lftp\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_HOST)" || { printf "\033[1;31m✗  DEPLOY_FTP_HOST manquant\033[0m (ex: luziapi.fr) — voir .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_USER)" || { printf "\033[1;31m✗  DEPLOY_FTP_USER manquant\033[0m (compte FTP) — voir .env.local\n"; exit 1; }
	@test -n "$$($(READ_FTP_PASS))" || { printf "\033[1;31m✗  DEPLOY_FTP_PASS manquant\033[0m dans .env.local\n"; exit 1; }
	@test -n "$(DEPLOY_FTP_PATH)" || { printf "\033[1;31m✗  DEPLOY_FTP_PATH manquant\033[0m (ex: public_html/wp-content/themes/luziapi) — voir .env.local\n"; exit 1; }
	@printf "✔  Cible FTPS : \033[36m%s@%s:%s\033[0m  (port %s, verify-cert=%s)\n" "$(DEPLOY_FTP_USER)" "$(DEPLOY_FTP_HOST)" "$(DEPLOY_FTP_PATH)" "$(DEPLOY_FTP_PORT)" "$(DEPLOY_FTP_VERIFY)"

.PHONY: deploy-dry
deploy-dry: deploy-check ## Simulation du déploiement (mirror --dry-run, n'envoie rien)
	@printf "🔍  Simulation FTPS (aucun fichier envoyé)…\n"
	@LFTP_PASSWORD="$$($(READ_FTP_PASS))" lftp -u '$(DEPLOY_FTP_USER)' --env-password -p $(DEPLOY_FTP_PORT) '$(DEPLOY_FTP_HOST)' \
		-e "$(LFTP_SETTINGS) mirror -R --delete --dry-run --verbose $(DEPLOY_EXCLUDES) $(THEME_SRC) $(DEPLOY_FTP_PATH); bye"

.PHONY: deploy
deploy: deploy-check composer-prod ## Déploie le thème sur o2switch (FTPS) puis restaure les deps de dev
	@printf "🚀  Déploiement FTPS vers \033[36m%s:%s\033[0m…\n" "$(DEPLOY_FTP_HOST)" "$(DEPLOY_FTP_PATH)"
	@LFTP_PASSWORD="$$($(READ_FTP_PASS))" lftp -u '$(DEPLOY_FTP_USER)' --env-password -p $(DEPLOY_FTP_PORT) '$(DEPLOY_FTP_HOST)' \
		-e "$(LFTP_SETTINGS) mirror -R --delete --verbose $(DEPLOY_EXCLUDES) $(THEME_SRC) $(DEPLOY_FTP_PATH); bye"
	@printf "🔧  Restauration des dépendances de dev en local…\n"
	@$(call IN_THEME,composer install --no-interaction)
	@printf "\n\033[1;32m✔  Thème déployé\033[0m  →  https://luziapi.fr\n\n"
	@bash scripts/post-deploy-check.sh

.PHONY: deploy-check-live
deploy-check-live: ## Vérifie que la prod répond (URL non cachées) — à lancer après tout déploiement
	@bash scripts/post-deploy-check.sh

.PHONY: verify-prod
verify-prod: ## Vérifie l'intégrité de TOUT le thème en prod (SHA-256 local↔prod, fichiers manquants/divergents)
	@bash scripts/verify-prod-integrity.sh

.PHONY: deploy-prune-prod
deploy-prune-prod: ## DRY-RUN : liste les orphelins src/ sur la prod (présents mais plus suivis en git) — rien supprimé
	@bash scripts/prune-prod-orphans.sh

.PHONY: deploy-prune-prod-apply
deploy-prune-prod-apply: ## Supprime les orphelins src/ de la prod (confirmation) — à lancer APRÈS un déploiement qui renomme/supprime
	@bash scripts/prune-prod-orphans.sh --apply
