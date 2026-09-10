#!/usr/bin/env bash
#
# Lance le test e2e du SUIVI DE COMMANDE sur la PRODUCTION.
#
#   scripts/e2e-tracking-prod.sh          → dry-run (aucun e-mail expédié)
#   scripts/e2e-tracking-prod.sh --send   → lien magique RÉEL vers l'adresse d'identité
#
# Dépose un script à jeton à usage unique à la racine du thème, l'appelle en
# HTTPS (jeton + payload embarqués), affiche le résumé, puis supprime le script
# dans tous les cas. Il crée UNE commande de test et la supprime en fin de run.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

THEME="www/wp-content/themes/luziapi"
SCRIPT="$THEME/tools/e2e-order-tracking-prod.php"
IDENTITY="$THEME/tools/.e2e-identity.json"
URL="https://www.luziapi.fr/wp-content/themes/luziapi/_e2e-tracking.php"

SEND=false
[ "${1:-}" = "--send" ] && SEND=true

for f in "$SCRIPT" "$IDENTITY" .env.local; do
    [ -f "$f" ] || { echo "❌  Fichier requis absent : $f" >&2; exit 1; }
done
for bin in lftp curl php openssl base64 python3; do
    command -v "$bin" >/dev/null || { echo "❌  Commande requise absente : $bin" >&2; exit 1; }
done

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

if $SEND; then
    echo "⚠️   Mode E-MAILS RÉELS : le lien magique va partir vers l'adresse d'identité."
else
    echo "ℹ️   Mode dry-run : aucun e-mail ne sera expédié (lien capturé en interne)."
fi

TOKEN="$(openssl rand -hex 16)"
SEND_PY="$($SEND && echo True || echo False)"
PAYLOAD="$(python3 -c "import json; d=json.load(open('$IDENTITY')); d.setdefault('options',{})['send_emails']=$SEND_PY; print(json.dumps(d))")"
B64="$(printf '%s' "$PAYLOAD" | base64 -w0)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
LOCAL="$WORK/_e2e-tracking.php"
sed -e "s/REPLACE_WITH_TOKEN/$TOKEN/" -e "s#B64PAYLOAD_PLACEHOLDER#$B64#" "$SCRIPT" > "$LOCAL"
php -l "$LOCAL" >/dev/null || { echo "❌  Script généré invalide." >&2; exit 1; }

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true;"
ftp_do() { lftp -u "$DEPLOY_FTP_USER,$DEPLOY_FTP_PASS" "$DEPLOY_FTP_HOST" -e "$FTP_OPTS $1 bye" >/dev/null 2>&1; }

echo "→  Dépôt du script à jeton…"
ftp_do "put -O . $LOCAL;" || { echo "❌  Échec du dépôt FTPS." >&2; exit 1; }

echo "→  Exécution sur la prod…"
RESULT="$(curl -sS "$URL?k=$TOKEN")"

echo "→  Suppression du script…"
ftp_do "rm _e2e-tracking.php;" || echo "⚠️   Suppression à vérifier manuellement."
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$URL")"
if [ "$CODE" = "404" ]; then echo "✓  Script supprimé (HTTP 404)."; else echo "⚠️   Script encore accessible (HTTP $CODE) — à retirer."; fi

echo
printf '%s' "$RESULT" > "$WORK/result.json"
if python3 - "$WORK/result.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
print(d.get("mode","?"), "|", d.get("summary","?"), "| all_passed:", d.get("all_passed"))
if d.get("fatal_error"):
    print("FATAL:", d["fatal_error"])
for r in d.get("results", []):
    if not r["ok"]:
        print("  ✗", r["label"], r.get("detail", ""))
print("cleanup:", d.get("cleanup"))
sys.exit(0 if d.get("all_passed") and not d.get("fatal_error") else 1)
PY
then
    echo "✔  Test e2e suivi prod OK."
else
    echo "✖  Test e2e suivi prod en échec ou réponse inattendue :"
    printf '%s\n' "$RESULT" | head -c 800
    exit 1
fi
