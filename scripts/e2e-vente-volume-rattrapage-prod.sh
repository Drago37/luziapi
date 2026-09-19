#!/usr/bin/env bash
#
# Lance le test e2e « Rattrapage de la remise de volume » sur la PRODUCTION.
#
#   scripts/e2e-vente-volume-rattrapage-prod.sh
#
# Dépose TROIS fichiers à usage unique à la racine du thème — le cœur e2e
# (`_e2e-vente-volume-rattrapage-core.php`), le cœur d'audit dont il dépend
# (`_audit-vente-volume-core.php`) et le wrapper à jeton
# (`_e2e-vente-volume-rattrapage.php`) — appelle le wrapper en HTTPS (jeton embarqué),
# affiche le résumé, puis supprime les trois dans tous les cas. Le test crée deux
# produits masqués + une commande isolés, seede puis nettoie sa recette : aucun
# e-mail, aucune donnée réelle touchée, tout est supprimé.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
E2E_CORE="${THEME}/tools/e2e-vente-volume-rattrapage-core.php"
AUDIT_CORE="${THEME}/tools/audit-vente-volume-core.php"
WRAPPER="${THEME}/tools/e2e-vente-volume-rattrapage-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_e2e-vente-volume-rattrapage.php"

for f in "${E2E_CORE}" "${AUDIT_CORE}" "${WRAPPER}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

echo "ℹ️   Test isolé : 2 produits masqués + 1 commande de test, aucun e-mail, recette seedée puis nettoyée, tout est supprimé."

TOKEN="$(openssl rand -hex 16)"

WORK="$(mktemp -d)"
uploaded=0

# Nettoyage garanti : dès que les fichiers sont déposés, on les retire, sinon un
# script à jeton resterait sur la prod. Vérification AVEC le jeton (wrapper présent
# = 200, supprimé = 404).
cleanup() {
  local status=$?
  if ((uploaded)); then
    ftp_do "rm _e2e-vente-volume-rattrapage.php; rm _e2e-vente-volume-rattrapage-core.php; rm _audit-vente-volume-core.php;" \
      && echo "→  Scripts distants supprimés." \
      || echo "⚠️   Suppression distante à vérifier MANUELLEMENT."
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "${URL}?k=${TOKEN}")" || code="000"
    if [[ "${code}" == "404" ]]; then
      echo "✓  Wrapper confirmé supprimé (HTTP 404)."
    else
      echo "⚠️   Wrapper encore accessible (HTTP ${code}) — à retirer MANUELLEMENT (jeton actif)."
    fi
  fi
  rm -rf "${WORK}"
  exit "${status}"
}
trap cleanup EXIT

LOCAL_E2E_CORE="${WORK}/_e2e-vente-volume-rattrapage-core.php"
LOCAL_AUDIT_CORE="${WORK}/_audit-vente-volume-core.php"
LOCAL_WRAPPER="${WORK}/_e2e-vente-volume-rattrapage.php"
cp "${E2E_CORE}" "${LOCAL_E2E_CORE}"
cp "${AUDIT_CORE}" "${LOCAL_AUDIT_CORE}"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${WRAPPER}" > "${LOCAL_WRAPPER}"
for f in "${LOCAL_E2E_CORE}" "${LOCAL_AUDIT_CORE}" "${LOCAL_WRAPPER}"; do
  php -l "${f}" >/dev/null || { echo "❌  Fichier invalide : ${f}" >&2; exit 1; }
done

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt des cœurs + du script à jeton…"
ftp_do "put -O . ${LOCAL_E2E_CORE}; put -O . ${LOCAL_AUDIT_CORE}; put -O . ${LOCAL_WRAPPER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }
uploaded=1

echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}")"

echo
printf '%s' "${RESULT}" > "${WORK}/result.json"
if python3 - "${WORK}/result.json" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except (json.JSONDecodeError, ValueError):
    print("Réponse non-JSON de la prod (fatal ou page d'erreur ?).")
    sys.exit(2)
print(d.get("mode", "?"), "|", d.get("summary", "?"), "| all_passed:", d.get("all_passed"))
if d.get("fatal_error"):
    print("FATAL:", d["fatal_error"])
for r in d.get("results", []):
    mark = "✓" if r["ok"] else "✗"
    print(f"  {mark} {r['label']}", r.get("detail", ""))
print("cleanup:", d.get("cleanup"))
sys.exit(0 if d.get("all_passed") and not d.get("fatal_error") else 1)
PY
then
  echo "✔  Test e2e rattrapage remise de volume prod OK."
else
  status=$?
  if [[ "${status}" -ne 1 ]]; then
    echo "✖  Réponse inattendue :"
    printf '%s\n' "${RESULT}" | head -c 800
  fi
  exit 1
fi
