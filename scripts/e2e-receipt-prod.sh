#!/usr/bin/env bash
#
# Lance le test e2e de la recette automatique (« Terminée » → recette) sur la PROD.
#
#   scripts/e2e-receipt-prod.sh
#
# Dépose un script à jeton à usage unique à la racine du thème, l'appelle en
# HTTPS, affiche le résumé, puis le supprime. La commande de test est passée
# « Terminée » via set_status (aucun e-mail, aucune fidélité, aucune écriture au
# journal d'activité) et tout est nettoyé (recettes + commandes + produit).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
SCRIPT="${THEME}/tools/e2e-receipt-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_e2e-receipt.php"

for f in "${SCRIPT}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

echo "ℹ️   Test isolé : produit masqué + commandes de test, aucun e-mail, tout nettoyé."

TOKEN="$(openssl rand -hex 16)"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
LOCAL="${WORK}/_e2e-receipt.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${SCRIPT}" > "${LOCAL}"
php -l "${LOCAL}" >/dev/null || { echo "❌  Script généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du script à jeton…"
ftp_do "put -O . ${LOCAL};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}")"

echo "→  Suppression du script…"
ftp_do "rm _e2e-receipt.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "${URL}")" || CODE="000"
if [[ "${CODE}" == "404" ]]; then
  echo "✓  Script supprimé (HTTP 404)."
else
  echo "⚠️   Script encore accessible (HTTP ${CODE}) — à retirer."
fi

echo
printf '%s' "${RESULT}" > "${WORK}/result.json"
if python3 - "${WORK}/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
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
  echo "✔  Test e2e recette prod OK."
else
  echo "✖  Test e2e recette prod en échec ou réponse inattendue :"
  printf '%s\n' "${RESULT}" | head -c 800
  exit 1
fi
