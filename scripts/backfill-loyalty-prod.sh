#!/usr/bin/env bash
#
# Rétro-crédite les pots de fidélité des commandes déjà « Terminée » sur la PRODUCTION.
#
#   scripts/backfill-loyalty-prod.sh                     # SIMULATION (rien écrit)
#   LUZIAPI_BACKFILL_APPLY=1 scripts/backfill-loyalty-prod.sh   # applique réellement
#   (ou : make backfill-loyalty-prod  /  make backfill-loyalty-prod APPLY=1)
#
# Dépose à la racine du thème le cœur partagé + un runner à jeton à usage unique,
# appelle le runner en HTTPS, affiche le rapport chiffré, puis supprime les deux
# fichiers dans tous les cas. Idempotent (clé credit:{orderId}, INSERT IGNORE) :
# rejouable sans doublon. Par défaut en simulation ; l'écriture exige un opt-in
# explicite.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
CORE="${THEME}/tools/backfill-loyalty.php"
RUNNER="${THEME}/tools/backfill-loyalty-prod.php"
BASE_URL="https://www.luziapi.fr/wp-content/themes/luziapi"
RUNNER_URL="${BASE_URL}/_backfill-loyalty.php"

for f in "${CORE}" "${RUNNER}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

apply=0
[[ "${LUZIAPI_BACKFILL_APPLY:-0}" == "1" ]] && apply=1
if [[ "${apply}" == "1" ]]; then
  echo "⚠️   MODE RÉEL : les pots des commandes terminées vont être écrits dans le journal de fidélité de la PROD."
else
  echo "ℹ️   SIMULATION : aucun pot ne sera écrit. Relancer avec LUZIAPI_BACKFILL_APPLY=1 pour appliquer."
fi

TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
LOCAL_RUNNER="${WORK}/_backfill-loyalty.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${RUNNER}" > "${LOCAL_RUNNER}"
php -l "${LOCAL_RUNNER}" >/dev/null || { echo "❌  Runner généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du cœur + du runner à jeton…"
ftp_do "put -O . ${CORE}; put -O . ${LOCAL_RUNNER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

QUERY="k=${TOKEN}"
[[ "${apply}" == "1" ]] && QUERY="${QUERY}&apply=1"
echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "${RUNNER_URL}?${QUERY}")"

echo "→  Suppression des scripts…"
ftp_do "rm _backfill-loyalty.php; rm backfill-loyalty.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "${RUNNER_URL}")" || CODE="000"
[[ "${CODE}" == "404" ]] && echo "✓  Runner supprimé (HTTP 404)." || echo "⚠️   Runner encore accessible (HTTP ${CODE}) — à retirer."

echo
printf '%s' "${RESULT}" > "${WORK}/result.json"
if python3 - "${WORK}/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
if d.get("error"):
    print("ERREUR:", d["error"]); sys.exit(1)
mode = "SIMULATION" if d.get("dry") else "APPLIQUÉ"
print(f"[{mode}] commandes terminées : {d.get('orders')} ; créditées : {d.get('credited')} "
      f"({d.get('pots')} pots) ; déjà au journal : {d.get('already')} ; "
      f"sans contact : {d.get('no_contact')} ; sans pot admissible : {d.get('no_pots')}.")
sys.exit(0)
PY
then
  echo "✔  Backfill fidélité prod terminé."
else
  echo "✖  Backfill fidélité prod : réponse inattendue :"
  printf '%s\n' "${RESULT}" | head -c 800
  exit 1
fi
