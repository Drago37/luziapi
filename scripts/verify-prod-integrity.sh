#!/usr/bin/env bash
#
# Vérification d'intégrité de TOUT le thème en prod : compare le SHA-256 de chaque
# fichier de code du thème suivi par git (hors tools/tests/vendor/docs/config) entre
# le dépôt local et la production, et liste précisément les fichiers MANQUANTS ou
# DIVERGENTS.
#
#   scripts/verify-prod-integrity.sh
#
# Motivation : un `make deploy` (mirror complet) interrompu laisse le thème à moitié
# uploadé → fatal PHP → 500 masqué par le cache PowerBoost (incidents de 2026, voir
# docs/prod-o2switch.md). `post-deploy-check.sh` détecte le 500 mais pas QUELS
# fichiers manquent ; `deploy-files.sh` ne vérifie que les fichiers qu'il vient de
# pousser. Ce script vérifie l'ensemble du thème, à lancer après un déploiement
# complet ou pour diagnostiquer une prod suspecte.
#
# Lecture seule côté prod (dépose un script à jeton qui ne fait que hacher, puis le
# supprime). Compare le checkout courant : prévenir si l'arbre n'est pas propre.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME_PREFIX="www/wp-content/themes/luziapi"
VURL="https://www.luziapi.fr/wp-content/themes/luziapi/_verify_integrity_run.php"

red() { printf '\033[31m%s\033[0m\n' "$*"; }
grn() { printf '\033[32m%s\033[0m\n' "$*"; }
die() { red "❌  $*"; exit 1; }

for bin in git lftp curl php openssl jq python3; do
  command -v "${bin}" >/dev/null || die "Commande requise absente : ${bin}"
done
[[ -f .env.local ]] || die "Fichier requis absent : .env.local"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "⚠️   Arbre de travail non propre : la comparaison porte sur les fichiers tels qu'ils sont sur le disque."
fi

# Même filtre que deploy-files.sh : fichiers de code du thème, hors outillage/tests/
# vendor/docs/config (vendor est géré à part ; le trou des 500 concernait src/).
mapfile -t REL < <(
  git ls-files "${THEME_PREFIX}" \
    | grep -Ev "^${THEME_PREFIX}/(tools|tests|tests-js|tests-browser|vendor)/" \
    | grep -Ev '\.(md)$' \
    | grep -Ev "^${THEME_PREFIX}/(composer\.(json|lock)|package(-lock)?\.json|playwright\.config\.js|\.php-cs-fixer.*|phpstan.*)$" \
    | sed "s#^${THEME_PREFIX}/##"
)
[[ "${#REL[@]}" -gt 0 ]] || die "Aucun fichier de code du thème trouvé — filtre ou dépôt inattendu."

for r in "${REL[@]}"; do
  [[ "${r}" =~ ^[A-Za-z0-9._/-]+$ && "${r}" != *".."* ]] \
    || die "Chemin non sûr : « ${r} » (caractères inattendus ou « .. »)."
done

echo "🔎  Vérification d'intégrité du thème en prod : ${#REL[@]} fichiers."

set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"

FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true; set net:timeout 20; set net:max-retries 2; set cmd:fail-exit true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye"; }

TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
VERIFY="${WORK}/_verify_integrity_run.php"
{
  echo "<?php declare(strict_types=1); header('Content-Type: application/json');"
  echo "if ((\$_GET['k'] ?? '') !== '${TOKEN}') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }"
  echo "\$files = ["
  for r in "${REL[@]}"; do echo "  '${r}',"; done
  echo "];"
  echo "\$h = [];"
  echo "foreach (\$files as \$f) { \$p = __DIR__.'/'.\$f; \$h[\$f] = is_file(\$p) ? hash_file('sha256', \$p) : 'MISSING'; }"
  echo "echo json_encode(['hashes'=>\$h], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);"
} > "${VERIFY}"
php -l "${VERIFY}" >/dev/null || die "Script de vérif généré invalide."

echo "→  Dépôt du script à jeton…"
ftp_do "put -O . ${VERIFY};" >/dev/null 2>&1 || die "Échec du dépôt FTPS du script de vérif."

echo "→  Empreintes de la prod…"
HTTP="$(curl -sS -o "${WORK}/prod.json" -w '%{http_code}' "${VURL}?k=${TOKEN}")" || HTTP="000"

echo "→  Suppression du script à jeton…"
ftp_do "rm _verify_integrity_run.php;" >/dev/null 2>&1 || echo "⚠️   Suppression à confirmer manuellement."
LEFT="$(curl -s -o /dev/null -w '%{http_code}' "${VURL}")" || LEFT="000"
[[ "${LEFT}" == "404" ]] || echo "⚠️   Script encore accessible (HTTP ${LEFT}) — à retirer."

[[ "${HTTP}" == "200" ]] || die "Vérif : HTTP ${HTTP} (attendu 200) — intégrité NON confirmée."
jq -e 'has("hashes")' "${WORK}/prod.json" >/dev/null 2>&1 \
  || die "Vérif : réponse JSON inattendue (début: $(head -c 160 "${WORK}/prod.json" | tr -d '\n'))."

echo
( cd "${THEME_PREFIX}"
  python3 - "${WORK}/prod.json" <<'PY'
import json, sys, hashlib, os
prod = json.load(open(sys.argv[1])).get("hashes", {})
missing = []
divergent = []
for f, ph in prod.items():
    if ph == "MISSING":
        missing.append(f); continue
    lh = hashlib.sha256(open(f, "rb").read()).hexdigest() if os.path.isfile(f) else "LOCAL-MISSING"
    if lh != ph:
        divergent.append(f)
total = len(prod)
ok = total - len(missing) - len(divergent)
for f in missing:
    print(f"  \033[31m✗ MANQUANT\033[0m  {f}")
for f in divergent:
    print(f"  \033[31m✗ DIVERGENT\033[0m {f}")
print(f"\n{ok}/{total} identiques"
      + (f", {len(missing)} manquant(s)" if missing else "")
      + (f", {len(divergent)} divergent(s)" if divergent else ""))
sys.exit(1 if (missing or divergent) else 0)
PY
) || die "Intégrité du thème NON conforme en prod — déploiement probablement incomplet."

grn "✅  Intégrité du thème conforme : la prod correspond au dépôt (${#REL[@]} fichiers)."
