#!/usr/bin/env bash
#
# Remet à zéro l'ISOLATION FIDÉLITÉ des tests e2e sur la PRODUCTION : purge le
# cluster d'identité des téléphones de test fixes (0600000000 / 0600000001) —
# liens d'identité + entrées de journal résiduelles laissées par un run interrompu.
# Données 100 % de test (aucun vrai client n'utilise ces numéros).
#
#   scripts/reset-test-loyalty-prod.sh            # DRY-RUN : rapporte seulement
#   scripts/reset-test-loyalty-prod.sh --apply    # supprime réellement (confirmation)
#   scripts/reset-test-loyalty-prod.sh --apply --yes
#   (ou : make reset-test-loyalty-prod / reset-test-loyalty-prod-apply)
#
# Dépose le cœur partagé + un runner à jeton à usage unique, appelle le runner en
# HTTPS, affiche le rapport, puis supprime les deux fichiers dans tous les cas.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
CORE="${THEME}/tools/reset-test-loyalty.php"
RUNNER="${THEME}/tools/reset-test-loyalty-prod.php"
BASE_URL="https://www.luziapi.fr/wp-content/themes/luziapi"
RUNNER_URL="${BASE_URL}/_reset-test-loyalty.php"

apply=0
assume_yes=0
while (($# > 0)); do
  case $1 in
    --apply) apply=1; shift ;;
    --yes|-y) assume_yes=1; shift ;;
    *) shift ;;
  esac
done

for f in "${CORE}" "${RUNNER}" .env.local; do
  [[ -f "${f}" ]] || { echo "❌  Fichier requis absent : ${f}" >&2; exit 1; }
done
for bin in git lftp curl php openssl python3; do
  command -v "${bin}" >/dev/null || { echo "❌  Commande requise absente : ${bin}" >&2; exit 1; }
done

# --- Gardes : opération prod à partir de code commité et poussé ---------------
[[ -z "$(git status --porcelain)" ]] || { echo "❌  Arbre de travail non propre — commit/stash avant." >&2; exit 1; }
branch="$(git rev-parse --abbrev-ref HEAD)"
case "${branch}" in
  main | release/* | hotfix/*) ;;
  *) echo "❌  Uniquement depuis main, release/* ou hotfix/* (branche : ${branch})." >&2; exit 1 ;;
esac
git fetch --quiet origin "${branch}"
[[ "$(git rev-parse @)" == "$(git rev-parse @{u})" ]] \
  || { echo "❌  ${branch} pas synchronisé avec origin/${branch} — pousse (ou pull) avant." >&2; exit 1; }

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

if ((apply)); then
  echo "⚠️   Mode --apply : suppression réelle du cluster d'identité de test sur la PROD."
else
  echo "ℹ️   DRY-RUN : rapport seulement, aucune suppression."
fi

TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"
uploaded=0

cleanup() {
  local status=$?
  if ((uploaded)); then
    ftp_do "rm _reset-test-loyalty.php; rm reset-test-loyalty.php;" \
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

LOCAL_RUNNER="${WORK}/_reset-test-loyalty.php"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${RUNNER}" > "${LOCAL_RUNNER}"
php -l "${LOCAL_RUNNER}" >/dev/null || { echo "❌  Runner généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du cœur + du runner à jeton…"
ftp_do "put -O . ${CORE}; put -O . ${LOCAL_RUNNER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }
uploaded=1

run_and_render() {
  local apply_flag=$1
  local result
  result="$(curl -sS -G "${RUNNER_URL}" --data-urlencode "k=${TOKEN}" --data-urlencode "apply=${apply_flag}")"
  printf '%s' "${result}" > "${WORK}/result.json"
  if python3 - "${WORK}/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
if d.get("error"):
    print("ERREUR:", d["error"]); sys.exit(2)
print(f"Cluster de test : {d.get('cluster_size')} clé(s) — "
      f"{d.get('ledger_rows')} entrée(s) de journal "
      f"(pots {d.get('ledger_pots_sum')}, droits {d.get('ledger_rights_sum')}), "
      f"{d.get('link_rows')} lien(s).")
for k in d.get("cluster_keys", []):
    print(f"  - {k}")
if d.get("applied"):
    print(f"\nPurge APPLIQUÉE : {d.get('deleted_ledger_rows')} entrée(s) de journal "
          f"et {d.get('deleted_link_rows')} lien(s) supprimé(s).")
else:
    print("\nDRY-RUN : rien supprimé.")
sys.exit(0)
PY
  then
    return 0
  else
    local code=$?
    if [[ "${code}" == "2" ]]; then
      return 1
    fi
    echo "✖  Réponse inattendue :"
    printf '%s\n' "${result}" | head -c 800
    return 1
  fi
}

echo "→  État du cluster de test sur la prod…"
echo
run_and_render "0" || { echo "❌  Rapport en échec — abandon." >&2; exit 1; }

if ((apply == 0)); then
  echo
  echo "ℹ️   DRY-RUN terminé. Relance avec --apply pour purger."
  exit 0
fi

if ((assume_yes == 0)); then
  echo
  read -r -p "PURGER ce cluster d'identité de test sur la PROD ? [o/N] " ans
  [[ "${ans}" =~ ^[oOyY]$ ]] || { echo "Annulé."; exit 1; }
fi

echo
echo "→  Purge…"
echo
run_and_render "1" || { echo "❌  Purge en échec — vérifier la prod." >&2; exit 1; }
echo
echo "✔  Purge terminée. Rejoue les e2e fidélité (make e2e-loyalty-prod, e2e-offered-pot-prod)."
