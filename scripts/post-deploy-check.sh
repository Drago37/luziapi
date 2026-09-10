#!/usr/bin/env bash
#
# Vérifie que la production répond après un déploiement, sur des URL NON CACHÉES.
# Motivation : un déploiement interrompu laisse le thème à moitié uploadé → fatal
# PHP → 500, mais le cache PowerBoost continue de servir la home en 200 et masque
# la panne (incident du 10 septembre 2026, voir docs/prod-o2switch.md).
#
# Usage : make deploy-check-live   (ou : bash scripts/post-deploy-check.sh [BASE_URL])

set -uo pipefail

BASE="${1:-https://www.luziapi.fr}"
RND="$RANDOM$RANDOM"

# URL choisies pour contourner le cache et charger réellement le thème.
PATHS=(
  "/wp-login.php?x=${RND}"
  "/?nocache=${RND}"
  "/boutique/?x=${RND}"
  "/mon-compte/?x=${RND}"
)

echo "🔎 Contrôle post-déploiement : ${BASE}"
fail=0
for path in "${PATHS[@]}"; do
  code="$(curl -sL -o /dev/null -w '%{http_code}' "${BASE}${path}")"
  if [ "${code}" = "200" ]; then
    printf '  \033[32m✓\033[0m %-28s → %s\n' "${path%%\?*}" "${code}"
  else
    printf '  \033[31m✗\033[0m %-28s → %s\n' "${path%%\?*}" "${code}"
    fail=1
  fi
done

if [ "${fail}" -eq 0 ]; then
  printf '\033[1;32m✅  Production saine\033[0m\n'
else
  printf '\033[1;31m❌  Production en erreur — déploiement probablement incomplet.\033[0m\n'
  printf '   Vérifier la complétude : find src/ | wc -l (prod, FTPS) vs le dépôt.\n'
  exit 1
fi
