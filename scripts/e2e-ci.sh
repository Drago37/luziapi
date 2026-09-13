#!/usr/bin/env bash
#
# Lance TOUS les tests e2e d'intégration LOCAUX de bout en bout.
#
#   scripts/e2e-ci.sh
#
# Découverte automatique : toute cible Make `e2e-<slug>-local` est exécutée. Ajouter
# un nouvel e2e = ajouter sa cible `-local` au Makefile, et il tourne ici (donc en
# CI) sans rien d'autre à modifier. Les variantes `-prod` (qui frappent le vrai site)
# ne sont JAMAIS incluses.
#
# Prérequis : la stack Docker (db + wordpress + wpcli) doit être démarrée et
# WordPress installé (voir le job CI, ou `make install` en local).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

# `e2e-local` (sans slug, test générique des commandes) exige une identité externe
# (LUZIAPI_E2E_PAYLOAD) : hors du périmètre auto. On ne prend que `e2e-<slug>-local`.
mapfile -t targets < <(grep -oE '^e2e-[a-z0-9-]+-local:' Makefile | sed 's/:$//' | sort -u)

if ((${#targets[@]} == 0)); then
  echo "❌  Aucune cible e2e-*-local trouvée dans le Makefile." >&2
  exit 1
fi

echo "▶  ${#targets[@]} suites e2e locales à jouer :"
printf '   - %s\n' "${targets[@]}"
echo

failed=()
for target in "${targets[@]}"; do
  echo "══════════════════════════════════════════════════════════════"
  echo "▶  make ${target}"
  echo "══════════════════════════════════════════════════════════════"
  if make "${target}"; then
    echo "✓  ${target}"
  else
    echo "✗  ${target}"
    failed+=("${target}")
  fi
  echo
done

echo "══════════════════════════════════════════════════════════════"
if ((${#failed[@]} > 0)); then
  echo "✖  ${#failed[@]}/${#targets[@]} suite(s) e2e en échec :"
  printf '   - %s\n' "${failed[@]}"
  exit 1
fi
echo "✔  ${#targets[@]}/${#targets[@]} suites e2e locales OK."
