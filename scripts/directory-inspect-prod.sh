#!/usr/bin/env bash
#
# Inspecte, en LECTURE SEULE sur la PRODUCTION : les listes Brevo (+ nb d'abonnés de la
# liste utilisée par le site) et les groupes du répertoire client (pour diagnostiquer une
# liste d'abonnés incomplète et des clients en double).
#
#   scripts/directory-inspect-prod.sh   (ou : make directory-inspect-prod)
#
# Dépose un script à jeton à usage unique, l'appelle en HTTPS, affiche le JSON, le supprime.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
WRAPPER="${THEME}/tools/directory-inspect-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_directory-inspect.php"

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
LOCAL_WRAPPER="${WORK}/_directory-inspect.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${WRAPPER}" > "${LOCAL_WRAPPER}"
php -l "${LOCAL_WRAPPER}" >/dev/null || { echo "❌  Wrapper généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du script à jeton…"
ftp_do "put -O . ${LOCAL_WRAPPER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Inspection (Brevo + répertoire) sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}")"

echo "→  Suppression du script…"
ftp_do "rm _directory-inspect.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "${URL}")" || CODE="000"
[[ "${CODE}" == "404" ]] && echo "✓  Script supprimé (HTTP 404)." || echo "⚠️   Script encore accessible (HTTP ${CODE}) — à retirer."

echo
printf '%s' "${RESULT}" | python3 -m json.tool 2>/dev/null || printf '%s\n' "${RESULT}"
