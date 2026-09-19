#!/usr/bin/env bash
#
# Inspection LECTURE SEULE de la fidélité d'un client sur la PRODUCTION, par
# recherche libre (nom, e-mail ou téléphone).
#
#   LUZIAPI_INSPECT_QUERY="gaultier" scripts/loyalty-customer-inspect-prod.sh
#   (ou : make loyalty-customer-inspect-prod Q="gaultier")
#
# Dépose le cœur partagé + un runner à jeton à usage unique, appelle le runner en
# HTTPS, affiche le rapport, puis supprime les deux fichiers dans tous les cas.
# Aucune écriture, aucun e-mail.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
CORE="${THEME}/tools/loyalty-customer-inspect.php"
RUNNER="${THEME}/tools/loyalty-customer-inspect-prod.php"
BASE_URL="https://www.luziapi.fr/wp-content/themes/luziapi"
RUNNER_URL="${BASE_URL}/_loyalty-customer-inspect.php"

SEARCH="${LUZIAPI_INSPECT_QUERY:-}"
[[ -n "${SEARCH}" ]] || { echo "❌  Terme de recherche requis : LUZIAPI_INSPECT_QUERY=\"nom\" (ou make … Q=\"nom\")." >&2; exit 1; }

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

echo "ℹ️   Inspection lecture seule : aucune écriture, aucun e-mail."

TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"
uploaded=0

cleanup() {
  local status=$?
  if ((uploaded)); then
    ftp_do "rm _loyalty-customer-inspect.php; rm loyalty-customer-inspect.php;" \
      && echo "→  Scripts distants supprimés." \
      || echo "⚠️   Suppression distante à vérifier MANUELLEMENT."
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "${RUNNER_URL}?k=${TOKEN}")" || code="000"
    if [[ "${code}" == "404" ]]; then
      echo "✓  Runner confirmé supprimé (HTTP 404)."
    else
      echo "⚠️   Runner encore accessible (HTTP ${code}) — à retirer MANUELLEMENT (jeton actif)."
    fi
  fi
  rm -rf "${WORK}"
  exit "${status}"
}
trap cleanup EXIT

LOCAL_RUNNER="${WORK}/_loyalty-customer-inspect.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${RUNNER}" > "${LOCAL_RUNNER}"
php -l "${LOCAL_RUNNER}" >/dev/null || { echo "❌  Runner généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du cœur + du runner à jeton…"
ftp_do "put -O . ${CORE}; put -O . ${LOCAL_RUNNER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }
uploaded=1

echo "→  Exécution sur la prod (recherche : « ${SEARCH} »)…"
RESULT="$(curl -sS -G "${RUNNER_URL}" --data-urlencode "k=${TOKEN}" --data-urlencode "q=${SEARCH}")"

echo
printf '%s' "${RESULT}" > "${WORK}/result.json"
if python3 - "${WORK}/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
if d.get("error"):
    print("ERREUR:", d["error"]); sys.exit(1)
print(f"Recherche « {d.get('search')} » — {d.get('matched')} commande(s) :")
for o in d.get("orders", []):
    flag = "déjà au journal" if o.get("in_ledger") else "PAS au journal"
    off = f" (+{o['offered']} offert)" if o.get("offered", 0) > 0 else ""
    print(f"  #{o.get('number'):<6} {o.get('date')}  {o.get('status'):<12} "
          f"{o.get('eligible')} pot(s){off}  {flag}  [{o.get('contact')}]")
print(f"\nSolde ACTUEL : {d.get('net_pots')} pots nets "
      f"→ {d.get('rights_available')} avantage(s) dispo "
      f"(acquis {d.get('rights_acquired')}, consommés {d.get('rights_consumed')}), "
      f"{d.get('pots_toward_next')}/15 vers le prochain (reste {d.get('pots_until_next')}).")
print(f"Commandes « Terminée » : {d.get('completed_eligible_pots')} pots admissibles au total ; "
      f"le backfill AJOUTERAIT {d.get('backfill_would_add_pots')} pot(s).")
sys.exit(0)
PY
then
  echo "✔  Inspection terminée."
else
  echo "✖  Réponse inattendue :"
  printf '%s\n' "${RESULT}" | head -c 800
  exit 1
fi
