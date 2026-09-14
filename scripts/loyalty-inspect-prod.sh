#!/usr/bin/env bash
#
# Inspecte une commande côté fidélité sur la PRODUCTION (LECTURE SEULE).
#
#   LUZIAPI_ORDER=106 scripts/loyalty-inspect-prod.sh
#   (ou : make loyalty-inspect-prod ORDER=106)
#
# Dépose un script à jeton à usage unique à la racine du thème, l'appelle en HTTPS,
# affiche le diagnostic (lignes, métas offert/fidélité/admissible, compteurs calculés,
# écritures du journal fidélité), puis le supprime. Aucune écriture.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

: "${LUZIAPI_ORDER:?commande à inspecter — ex. LUZIAPI_ORDER=106 (ou make loyalty-inspect-prod ORDER=106)}"
[[ "${LUZIAPI_ORDER}" =~ ^[0-9]+$ ]] || { echo "❌  LUZIAPI_ORDER doit être un nombre." >&2; exit 1; }

THEME="www/wp-content/themes/luziapi"
WRAPPER="${THEME}/tools/loyalty-inspect-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_loyalty-inspect.php"

for f in "${WRAPPER}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
LOCAL_WRAPPER="${WORK}/_loyalty-inspect.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${WRAPPER}" > "${LOCAL_WRAPPER}"
php -l "${LOCAL_WRAPPER}" >/dev/null || { echo "❌  Wrapper généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du script à jeton…"
ftp_do "put -O . ${LOCAL_WRAPPER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Inspection de la commande #${LUZIAPI_ORDER} sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}&order=${LUZIAPI_ORDER}")"

echo "→  Suppression du script…"
ftp_do "rm _loyalty-inspect.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "${URL}")" || CODE="000"
[[ "${CODE}" == "404" ]] && echo "✓  Script supprimé (HTTP 404)." || echo "⚠️   Script encore accessible (HTTP ${CODE}) — à retirer."

echo
printf '%s' "${RESULT}" | python3 -m json.tool 2>/dev/null || printf '%s\n' "${RESULT}"
