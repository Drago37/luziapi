#!/usr/bin/env bash
#
# Lance le test e2e de l'auto-envoi newsletter sur la PRODUCTION.
#
#   scripts/e2e-newsletter-prod.sh
#
# Dépose DEUX fichiers à usage unique à la racine du thème — le cœur partagé
# (`_e2e-newsletter-core.php`, car `tools/` n'existe pas en prod) et le wrapper à
# jeton (`_e2e-newsletter.php`) — appelle le wrapper en HTTPS (jeton embarqué),
# affiche le résumé, puis supprime les deux dans tous les cas.
#
# SÛR : aucun e-mail ni SMS ne part (le seul canal, Brevo en HTTP, est intercepté
# avant tout départ), l'article de test est supprimé et l'event dé-planifié.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME="www/wp-content/themes/luziapi"
CORE="${THEME}/tools/e2e-newsletter.php"
WRAPPER="${THEME}/tools/e2e-newsletter-prod.php"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_e2e-newsletter.php"

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

echo "ℹ️   Test isolé : article de test éphémère, envoi Brevo intercepté (aucun e-mail/SMS), tout nettoyé."

TOKEN="$(openssl rand -hex 16)"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
LOCAL_CORE="${WORK}/_e2e-newsletter-core.php"
LOCAL_WRAPPER="${WORK}/_e2e-newsletter.php"
cp "${CORE}" "${LOCAL_CORE}"
sed -e "s/REPLACE_WITH_TOKEN/${TOKEN}/" "${WRAPPER}" > "${LOCAL_WRAPPER}"
php -l "${LOCAL_CORE}" >/dev/null || { echo "❌  Cœur invalide." >&2; exit 1; }
php -l "${LOCAL_WRAPPER}" >/dev/null || { echo "❌  Wrapper généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du cœur + du script à jeton…"
ftp_do "put -O . ${LOCAL_CORE}; put -O . ${LOCAL_WRAPPER};" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "${URL}?k=${TOKEN}")"

echo "→  Suppression des scripts…"
ftp_do "rm _e2e-newsletter.php; rm _e2e-newsletter-core.php;" || echo "⚠️   Suppression à vérifier manuellement."
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
  echo "✔  Test e2e newsletter prod OK."
else
  echo "✖  Test e2e newsletter prod en échec ou réponse inattendue :"
  printf '%s\n' "${RESULT}" | head -c 800
  exit 1
fi
