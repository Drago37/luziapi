#!/usr/bin/env bash
#
# Déploiement FTPS CIBLÉ des fichiers du thème modifiés depuis un point de repère.
#
#   scripts/deploy-files.sh [<base-ref>] [--yes]
#
# Calcule lui-même le delta (git diff <base>..HEAD), n'envoie que les fichiers de
# code du thème (exclut tools/tests/docs/config), vide l'OPcache et vérifie les
# SHA-256 local ↔ prod par script à jeton, puis lance le contrôle post-déploiement.
#
# Convention actuelle : `main` = prod à jour (pas encore de gitflow / tag de release).
#   - Sans <base-ref>, on repart du dernier déploiement mémorisé dans
#     scripts/.last-deploy (fichier local, NON versionné). Au premier usage, passer
#     explicitement le dernier commit déployé.
#   - Après un déploiement réussi, HEAD est enregistré dans scripts/.last-deploy.
#
# GARDE DE DÉPLOIEMENT (bloquante) : on ne déploie QUE si le code est poussé sur
# main ET que la CI est verte sur HEAD. Sinon on refuse.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

THEME_PREFIX="www/wp-content/themes/luziapi"
MARKER="scripts/.last-deploy"
WORKFLOW="Qualité du thème"

ASSUME_YES=0
BASE=""
for arg in "$@"; do
  case "$arg" in
    --yes|-y) ASSUME_YES=1 ;;
    *) BASE="$arg" ;;
  esac
done

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
die()  { red "❌  $*"; exit 1; }

for bin in git gh lftp curl php openssl jq python3; do
  command -v "$bin" >/dev/null || die "Commande requise absente : $bin"
done
[[ -f .env.local ]] || die "Fichier requis absent : .env.local"

# --- Garde 1 : arbre propre ------------------------------------------------
[[ -z "$(git status --porcelain)" ]] || die "Arbre de travail non propre — commit/stash avant de déployer."

# --- Garde 2 : code poussé sur main (rien en avance ni en retard) -----------
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[[ "$BRANCH" == "main" ]] || die "Déploiement uniquement depuis main (branche courante : $BRANCH)."
git fetch --quiet origin main
LOCAL_SHA="$(git rev-parse @)"
REMOTE_SHA="$(git rev-parse @{u})"
[[ "$LOCAL_SHA" == "$REMOTE_SHA" ]] || die "main n'est pas synchronisé avec origin/main — pousse (ou pull) avant de déployer."

# --- Garde 3 : CI verte sur HEAD -------------------------------------------
RUN="$(gh run list --branch main --workflow "$WORKFLOW" --limit 20 \
        --json headSha,status,conclusion,databaseId \
        | jq -c --arg sha "$LOCAL_SHA" 'map(select(.headSha == $sha)) | first')"
[[ "$RUN" != "null" && -n "$RUN" ]] || die "Aucun run CI « $WORKFLOW » pour HEAD ($LOCAL_SHA) — attends que la CI démarre/finisse."
STATUS="$(jq -r '.status' <<<"$RUN")"
CONCLUSION="$(jq -r '.conclusion' <<<"$RUN")"
[[ "$STATUS" == "completed" ]]  || die "CI pas terminée sur HEAD (status=$STATUS) — attends la fin de la CI."
[[ "$CONCLUSION" == "success" ]] || die "CI NON verte sur HEAD (conclusion=$CONCLUSION) — déploiement bloqué."
grn "✓ Gardes OK : arbre propre, main poussé, CI verte sur ${LOCAL_SHA:0:8}."

# --- Base de calcul du delta -----------------------------------------------
if [[ -z "$BASE" ]]; then
  [[ -f "$MARKER" ]] || die "Pas de base : passe le dernier commit déployé en argument (aucun $MARKER)."
  BASE="$(<"$MARKER")"
fi
git cat-file -e "${BASE}^{commit}" 2>/dev/null || die "Base invalide : $BASE"

# --- Liste des fichiers de code du thème à déployer -------------------------
# `--diff-filter=ACMR` : on ne pousse que les fichiers ajoutés/copiés/modifiés/
# renommés — les suppressions (D) ne sont pas gérées par un upload et sont
# signalées à part (à retirer manuellement du serveur).
theme_code_filter() {
  grep -Ev "^${THEME_PREFIX}/(tools|tests)/" \
    | grep -Ev '\.(md)$' \
    | grep -Ev "^${THEME_PREFIX}/(composer\.(json|lock)|package(-lock)?\.json|\.php-cs-fixer.*|phpstan.*)$" \
    | grep -E "^${THEME_PREFIX}/" || true
}
mapfile -t FILES < <(git diff --name-only --diff-filter=ACMR "${BASE}..HEAD" -- "${THEME_PREFIX}" | theme_code_filter)
mapfile -t DELETED < <(git diff --name-only --diff-filter=D "${BASE}..HEAD" -- "${THEME_PREFIX}" | theme_code_filter)
[[ "${#FILES[@]}" -gt 0 ]] || die "Aucun fichier de code du thème entre $BASE et HEAD — rien à déployer."
if [[ "${#DELETED[@]}" -gt 0 ]]; then
  echo "⚠️  Fichiers supprimés NON gérés par ce script (à retirer manuellement du serveur) :"
  printf '     - %s\n' "${DELETED[@]#"${THEME_PREFIX}"/}"
fi

# chemins relatifs à la racine du thème (le compte FTP y est chrooté)
REL=(); for f in "${FILES[@]}"; do REL+=("${f#"$THEME_PREFIX"/}"); done
HAS_PHP=0; for r in "${REL[@]}"; do [[ "$r" == *.php ]] && HAS_PHP=1; done

# Garde de robustesse : les chemins sont interpolés dans le PHP généré et des
# commandes lftp. On refuse tout caractère hors du jeu attendu des chemins de thème
# (lettres, chiffres, . _ - /), qui pourrait casser/injecter ces contextes.
for r in "${REL[@]}"; do
  [[ "$r" =~ ^[A-Za-z0-9._/-]+$ ]] || die "Chemin non sûr pour le déploiement : « ${r} » (caractères inattendus)."
done

echo
echo "Base   : $BASE"
echo "HEAD   : ${LOCAL_SHA:0:8}"
echo "Fichiers à déployer (${#REL[@]}) :"
printf '  - %s\n' "${REL[@]}"
echo
if [[ "$ASSUME_YES" -ne 1 ]]; then
  read -r -p "Déployer ces fichiers en prod ? [o/N] " ans
  [[ "$ans" =~ ^[oOyY]$ ]] || die "Annulé."
fi

# --- Env FTP ---------------------------------------------------------------
set -a; . ./.env.local; set +a
: "${DEPLOY_FTP_USER:?manquant dans .env.local}"
: "${DEPLOY_FTP_PASS:?manquant dans .env.local}"
: "${DEPLOY_FTP_HOST:?manquant dans .env.local}"
P="${DEPLOY_FTP_PATH:-.}"
# `cmd:fail-exit true` : le lot lftp s'arrête au premier ordre en échec.
FTP_OPTS="set ftp:ssl-force true; set ssl:verify-certificate yes; set ftp:ssl-protect-data true; set passive-mode true; set net:timeout 20; set net:max-retries 2; set cmd:fail-exit true;"
ftp_do() { lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} $1 bye"; }

# Fichiers temporaires : préfixe `.ht` — Apache refuse `.ht*` par défaut, donc
# aucune source PHP n'est servable en clair pendant la fenêtre d'upload. La
# bascule se fait par rename atomique côté serveur une fois tout monté.
PIDTAG="$$"
temp_rel() {
  printf '%s/.ht-deploying-%s-%s' "$(dirname "$1")" "${PIDTAG}" "$(basename "$1")"
}

cleanup_temps() {
  local cmds="" r
  for r in "${REL[@]}"; do cmds+=" rm -f ${P}/$(temp_rel "${r}");"; done
  ftp_do "${cmds}" >/dev/null 2>&1 || true
}
fail_deploy() { cleanup_temps; die "$1"; }

# --- Upload vers des noms temporaires (prod intacte à ce stade) -------------
echo "→  Upload FTPS (temporaire)…"
trap 'cleanup_temps' ERR
(
  cd "${THEME_PREFIX}"
  # Crée au besoin les dossiers cibles (nouveaux répertoires), puis dépose les temp.
  mkdirs="" puts="" seen=" "
  for r in "${REL[@]}"; do
    d="$(dirname "${r}")"
    if [[ "${seen}" != *" ${d} "* ]]; then mkdirs+=" mkdir -p -f ${P}/${d};"; seen+="${d} "; fi
    puts+=" put ${r} -o ${P}/$(temp_rel "${r}");"
  done
  lftp -u "${DEPLOY_FTP_USER},${DEPLOY_FTP_PASS}" "${DEPLOY_FTP_HOST}" -e "${FTP_OPTS} ${mkdirs} ${puts} bye"
)

# --- Bascule atomique + OPcache + empreintes, par script à jeton -----------
TOKEN="$(openssl rand -hex 16)"
WORK="$(mktemp -d)"; trap 'rm -rf "${WORK}"' EXIT
VERIFY="${WORK}/_deploy_verify_run.php"
{
  echo "<?php declare(strict_types=1); header('Content-Type: application/json');"
  echo "if ((\$_GET['k'] ?? '') !== '${TOKEN}') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }"
  echo "\$pidtag = '${PIDTAG}';"
  echo "\$files = ["
  for r in "${REL[@]}"; do echo "  '${r}',"; done
  echo "];"
  echo "\$h = []; \$errors = [];"
  echo "foreach (\$files as \$f) {"
  echo "  \$tmp = __DIR__.'/'.dirname(\$f).'/.ht-deploying-'.\$pidtag.'-'.basename(\$f); \$final = __DIR__.'/'.\$f;"
  echo "  if (is_file(\$tmp)) { if (! @rename(\$tmp, \$final)) { \$errors[] = 'rename: '.\$f; } }"
  echo "  else { \$errors[] = 'temp-absent: '.\$f; }"
  echo "  \$h[\$f] = is_file(\$final) ? hash_file('sha256', \$final) : 'MISSING';"
  echo "}"
  echo "echo json_encode(['opcache_reset'=>function_exists('opcache_reset')?opcache_reset():null,'errors'=>\$errors,'hashes'=>\$h], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);"
} > "${VERIFY}"
php -l "${VERIFY}" >/dev/null || fail_deploy "Script de vérif généré invalide."

VURL="https://www.luziapi.fr/wp-content/themes/luziapi/_deploy_verify_run.php"
echo "→  Bascule atomique + OPcache + empreintes (script à jeton)…"
ftp_do "put -O . ${VERIFY};" >/dev/null 2>&1 || fail_deploy "Échec du dépôt du script de vérif."
# `|| HTTP=000` : ne pas laisser set -e avaler un échec réseau ici.
HTTP="$(curl -sS -o "${WORK}/prod.json" -w '%{http_code}' "${VURL}?k=${TOKEN}")" || HTTP="000"
ftp_do "rm _deploy_verify_run.php;" >/dev/null 2>&1 || echo "⚠️  Suppression du script de vérif à confirmer."
LEFT="$(curl -s -o /dev/null -w '%{http_code}' "${VURL}")" || LEFT="000"
[[ "${LEFT}" == "404" ]] || echo "⚠️  Script de vérif encore accessible (HTTP ${LEFT}) — à retirer."

# La vérif n'est fiable QUE si c'est un vrai 200 au JSON attendu : une page
# d'erreur / 403 / 500 / réponse cachée ne doit jamais passer pour un succès
# (sinon marqueur avancé + prod périmée invisible).
[[ "${HTTP}" == "200" ]] || fail_deploy "Vérif : HTTP ${HTTP} (attendu 200) — déploiement NON confirmé, prod à revérifier."
jq -e 'has("errors") and has("hashes")' "${WORK}/prod.json" >/dev/null 2>&1 \
  || fail_deploy "Vérif : réponse JSON inattendue — déploiement NON confirmé (début: $(head -c 160 "${WORK}/prod.json" | tr -d '\n'))."
HASH_COUNT="$(jq -r '.hashes | length' "${WORK}/prod.json")"
[[ "${HASH_COUNT}" == "${#REL[@]}" ]] || fail_deploy "Vérif : ${HASH_COUNT} empreintes pour ${#REL[@]} fichiers attendus — déploiement NON confirmé."
RENAME_ERRORS="$(jq -r '.errors | length' "${WORK}/prod.json")"
[[ "${RENAME_ERRORS}" == "0" ]] || fail_deploy "Bascule incomplète (erreurs: $(jq -c '.errors' "${WORK}/prod.json")) — prod à revérifier."
trap - ERR

# --- Comparaison SHA -------------------------------------------------------
echo "→  Comparaison SHA-256…"
( cd "$THEME_PREFIX"
  python3 - "${WORK}/prod.json" <<'PY'
import json,sys,hashlib,os
prod=json.load(open(sys.argv[1])).get("hashes",{})
bad=0
for f,ph in prod.items():
    lh=hashlib.sha256(open(f,'rb').read()).hexdigest() if os.path.isfile(f) else "LOCAL-MISSING"
    ok=(lh==ph)
    print(("  \033[32m✓\033[0m " if ok else "  \033[31m✗\033[0m ")+f)
    if not ok:
        bad+=1; print(f"      local={lh}\n      prod ={ph}")
print(f"\n{len(prod)-bad}/{len(prod)} identiques"+("" if bad==0 else f", {bad} DIVERGENT(S)"))
sys.exit(1 if bad else 0)
PY
) || die "Empreintes divergentes — déploiement à revérifier."

# --- Contrôle live (URL non cachées) ---------------------------------------
bash scripts/post-deploy-check.sh

# --- Mémorise le point de déploiement --------------------------------------
echo "$LOCAL_SHA" > "$MARKER"
grn "✅  Déploiement terminé. OPcache vidé : $(jq -r '.opcache_reset' "${WORK}/prod.json"). Repère mis à jour ($MARKER)."
[[ "$HAS_PHP" -eq 1 ]] || echo "ℹ️  (aucun PHP modifié — OPcache vidé par sûreté)"
printf '\033[1;33m📝  À FAIRE : consigner ce déploiement dans docs/prod-o2switch.md (règle AGENTS.md).\033[0m\n'
