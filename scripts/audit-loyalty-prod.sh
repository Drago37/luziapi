#!/usr/bin/env bash
#
# Audit de dérive du programme de fidélité sur la PRODUCTION (lecture seule).
#
#   scripts/audit-loyalty-prod.sh
#   LUZIAPI_AUDIT_YEAR=2026 scripts/audit-loyalty-prod.sh
#
# Dépose deux fichiers à usage unique à la racine du thème — le cœur partagé
# (`_audit-loyalty-drift-core.php`) et le wrapper à jeton (`_audit-loyalty-drift.php`) —
# appelle le wrapper en HTTPS (jeton embarqué), affiche le rapport, puis supprime les
# deux dans tous les cas. Aucune écriture, aucun e-mail. Sort en erreur si dérive.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
CORE="${THEME}/tools/audit-loyalty-drift-core.php"
WRAPPER="${THEME}/tools/audit-loyalty-drift-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_audit-loyalty-drift.php"

for f in "${CORE}" "${WRAPPER}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

YEAR_QS=""
[[ -n "${LUZIAPI_AUDIT_YEAR:-}" ]] && YEAR_QS="&year=${LUZIAPI_AUDIT_YEAR}"

echo "ℹ️   Audit lecture seule : aucune écriture, aucun e-mail."

TOKEN="$(openssl rand -hex 16)"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
LOCAL_CORE="${WORK}/_audit-loyalty-drift-core.php"
LOCAL_WRAPPER="${WORK}/_audit-loyalty-drift.php"
cp "${CORE}" "${LOCAL_CORE}"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${WRAPPER}" > "${LOCAL_WRAPPER}"
php -l "${LOCAL_CORE}" >/dev/null || { echo "❌  Cœur invalide." >&2; exit 1; }
php -l "${LOCAL_WRAPPER}" >/dev/null || { echo "❌  Wrapper généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du cœur + du script à jeton…"
ftp_do "put -O . ${LOCAL_CORE}; put -O . ${LOCAL_WRAPPER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}${YEAR_QS}")"

echo "→  Suppression des scripts…"
ftp_do "rm _audit-loyalty-drift.php; rm _audit-loyalty-drift-core.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "${URL}")" || CODE="000"
if [[ "${CODE}" == "404" ]]; then
  echo "✓  Scripts supprimés (HTTP 404)."
else
  echo "⚠️   Wrapper encore accessible (HTTP ${CODE}) — à retirer."
fi

echo
printf '%s' "${RESULT}" > "${WORK}/result.json"
if python3 - "${WORK}/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
if d.get("fatal_error"):
    print("FATAL:", d["fatal_error"]); sys.exit(1)
scope = f"année {d['year']}" if d.get("year") else "tout l'historique"
print(f"Audit de dérive fidélité — {scope}")
if not d.get("has_drift"):
    print("✔  Aucune dérive : chaque commande admissible est créditée, aucun crédit orphelin.")
    sys.exit(0)
for r in d.get("gaps", []):
    print(f"  ⚠  Commande {r['order']} (#{r['id']}) admissible non créditée : {r['pots']} pot(s) manquant(s)")
for r in d.get("orphans", []):
    print(f"  ⚠  Crédit orphelin : commande #{r['order_id']} disparue, {r['pots']} pot(s) encore au journal")
print(f"✖  {d['anomaly_count']} anomalie(s) : {d['total_missing_pots']} pot(s) non crédité(s), {d['total_orphan_pots']} pot(s) orphelin(s).")
sys.exit(1)
PY
then
  echo "✔  Audit prod terminé : aucune dérive."
else
  status=$?
  if [[ "${status}" -ne 1 ]]; then
    echo "✖  Réponse inattendue :"
    printf '%s\n' "${RESULT}" | head -c 800
  fi
  exit 1
fi
