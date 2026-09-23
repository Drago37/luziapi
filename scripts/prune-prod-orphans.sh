#!/usr/bin/env bash
#
# Retire de la PROD les fichiers ORPHELINS sous `src/` : ceux présents sur le
# serveur mais qui ne sont plus suivis par git (anciens chemins d'un gros
# renommage, fichiers supprimés). `scripts/deploy-files.sh` sait uploader mais
# PAS supprimer — cet outil complète le déploiement d'une release qui renomme /
# supprime beaucoup de fichiers (ex. 1.4.0 : Pilotage → Shop).
#
#   scripts/prune-prod-orphans.sh            # DRY-RUN : liste seulement
#   scripts/prune-prod-orphans.sh --apply    # supprime réellement (confirmation)
#   scripts/prune-prod-orphans.sh --apply --yes
#
# Sûreté :
#   - portée STRICTEMENT limitée à `src/` (jamais vendor/, inc/, uploads…) ;
#   - un jeton liste les fichiers `src/` réellement sur prod ; on ne supprime
#     QUE ceux absents de `git ls-files … src/` ;
#   - chaque chemin est revalidé (jeu de caractères, pas de `..`) ;
#   - dry-run par défaut ; suppression seulement avec --apply + confirmation.
#
# À lancer JUSTE APRÈS un `scripts/deploy-files.sh` réussi (mêmes gardes ; la CI
# verte a été vérifiée sur le même HEAD lors de l'upload).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

THEME_PREFIX="www/wp-content/themes/luziapi"
VERIFY_URL="https://www.luziapi.fr/wp-content/themes/luziapi/_prune_orphans_run.php"

apply=0
assume_yes=0
while (($# > 0)); do
  case $1 in
    --apply) apply=1; shift ;;
    --yes|-y) assume_yes=1; shift ;;
    *) shift ;;
  esac
done

red() { printf '\033[31m%s\033[0m\n' "$*"; }
grn() { printf '\033[32m%s\033[0m\n' "$*"; }
die() { red "❌  $*"; exit 1; }

for bin in git lftp curl php openssl jq; do
  command -v "${bin}" >/dev/null || die "Commande requise absente : ${bin}"
done
[[ -f .env.local ]] || die "Fichier requis absent : .env.local"

# --- Gardes (identiques à deploy-files.sh, hors CI déjà vérifiée à l'upload) ---
[[ -z "$(git status --porcelain)" ]] || die "Arbre de travail non propre — commit/stash avant."
branch="$(git rev-parse --abbrev-ref HEAD)"
case "${branch}" in
  main | release/* | hotfix/*) ;;
  *) die "Uniquement depuis main, release/* ou hotfix/* (branche : ${branch})." ;;
esac
git fetch --quiet origin "${branch}"
[[ "$(git rev-parse @)" == "$(git rev-parse @{u})" ]] \
  || die "${branch} pas synchronisé avec origin/${branch} — pousse (ou pull) avant."

# --- Liste locale des fichiers suivis sous src/ (référence de vérité) -------
mapfile -t LOCAL_FILES < <(git ls-files "${THEME_PREFIX}/src" | sed "s#^${THEME_PREFIX}/##")
[[ "${#LOCAL_FILES[@]}" -gt 0 ]] || die "Aucun fichier suivi sous src/ — filtre inattendu."
declare -A LOCAL_SET=()
for f in "${LOCAL_FILES[@]}"; do LOCAL_SET["${f}"]=1; done

# --- Env FTP ---------------------------------------------------------------
set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"
P="${DEPLOY_FTP_PATH:-.}"
FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true; set net:timeout 20; set net:max-retries 2; set cmd:fail-exit true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye"; }

# --- Jeton : liste les fichiers src/ réellement présents sur la prod --------
TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"; trap 'rm -rf "${WORK}"' EXIT
LISTER="${WORK}/_prune_orphans_run.php"
{
  echo "<?php declare(strict_types=1); header('Content-Type: application/json');"
  echo "if ((\$_GET['k'] ?? '') !== '${TOKEN}') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }"
  echo "\$base = realpath(__DIR__ . '/src'); \$files = [];"
  echo "if (\$base !== false && is_dir(\$base)) {"
  echo "  \$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\$base, FilesystemIterator::SKIP_DOTS));"
  echo "  foreach (\$it as \$f) { if (\$f->isFile()) { \$files[] = 'src/' . str_replace('\\\\', '/', substr(\$f->getPathname(), strlen(\$base) + 1)); } }"
  echo "}"
  echo "sort(\$files); echo json_encode(['files'=>\$files], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);"
} > "${LISTER}"
php -l "${LISTER}" >/dev/null || die "Script de liste généré invalide."

echo "→  Liste des fichiers src/ sur la prod (jeton)…"
ftp_do "put -O . ${LISTER};" >/dev/null 2>&1 || die "Échec du dépôt du script de liste."
HTTP="$(curl -sS -o "${WORK}/prod.json" -w '%{http_code}' "${VERIFY_URL}?k=${TOKEN}")" || HTTP="000"
ftp_do "rm _prune_orphans_run.php;" >/dev/null 2>&1 || echo "⚠️  Suppression du script de liste à confirmer."
LEFT="$(curl -s -o /dev/null -w '%{http_code}' "${VERIFY_URL}")" || LEFT="000"
[[ "${LEFT}" == "404" ]] || echo "⚠️  Script de liste encore accessible (HTTP ${LEFT}) — à retirer."
[[ "${HTTP}" == "200" ]] || die "Liste : HTTP ${HTTP} (attendu 200) — abandon."
jq -e 'has("files")' "${WORK}/prod.json" >/dev/null 2>&1 || die "Liste : JSON inattendu — abandon."

# --- Calcul des orphelins : sur prod mais plus suivis en git ----------------
mapfile -t PROD_FILES < <(jq -r '.files[]' "${WORK}/prod.json")
ORPHANS=()
for pf in "${PROD_FILES[@]}"; do
  [[ -n "${LOCAL_SET[${pf}]:-}" ]] && continue
  # Revalidation défensive : uniquement src/, jeu de caractères sûr, pas de « .. ».
  [[ "${pf}" =~ ^src/[A-Za-z0-9._/-]+$ && "${pf}" != *".."* ]] \
    || die "Chemin orphelin non sûr : « ${pf} » — abandon."
  ORPHANS+=("${pf}")
done

if [[ "${#ORPHANS[@]}" -eq 0 ]]; then
  grn "✅  Aucun orphelin sous src/ — la prod est propre."
  exit 0
fi

echo
echo "Orphelins sous src/ (présents sur prod, plus suivis en git) — ${#ORPHANS[@]} :"
printf '  - %s\n' "${ORPHANS[@]}"
echo

if [[ "${apply}" -ne 1 ]]; then
  echo "ℹ️  DRY-RUN : rien supprimé. Relance avec --apply pour supprimer."
  exit 0
fi

if [[ "${assume_yes}" -ne 1 ]]; then
  read -r -p "SUPPRIMER ces ${#ORPHANS[@]} fichiers de la prod ? [o/N] " ans
  [[ "${ans}" =~ ^[oOyY]$ ]] || die "Annulé."
fi

# --- Suppression FTPS des orphelins ----------------------------------------
echo "→  Suppression FTPS…"
rm_cmds=""
for o in "${ORPHANS[@]}"; do rm_cmds+=" rm -f ${P}/${o};"; done
ftp_do "${rm_cmds}" || die "Échec d'une suppression — prod à revérifier."

grn "✅  ${#ORPHANS[@]} orphelin(s) supprimé(s). Lance « make verify-prod » pour confirmer l'intégrité."
printf '\033[1;33m📝  À FAIRE : consigner cette purge dans docs/prod-o2switch.md.\033[0m\n'
