# Contribuer à LuziApi

Ce document décrit les conventions techniques du projet. Il s'adresse aux développeurs comme aux
agents IA. Lire également `AGENTS.md` avant toute intervention : il contient les règles de
collaboration, de publication et de déploiement propres à LuziApi.

## Flux Git — GitFlow (OBLIGATOIRE depuis la 1.0.0)

Le projet est **en production depuis la 1.0.0** (11 septembre 2026) : la phase alpha/bêta où l'on
committait directement sur `main` est **terminée**. À partir de maintenant, **GitFlow est obligatoire**,
y compris pour les agents IA. **Ne jamais committer ni pousser directement sur `main`.**

**Branches :**

- **`main`** = production. Ne reçoit QUE des merges de `release/*` ou `hotfix/*`, et **chaque merge est
  tagué `X.Y.Z`** (sans préfixe `v`). Le HEAD de `main` est l'état déployé. Aucun commit direct.
- **`develop`** = intégration. Base de toutes les fonctionnalités ; c'est là que le travail courant
  s'accumule entre deux releases.
- **`feature/<slug>`** : partent de `develop`, y retournent par **Pull Request** (jamais de merge direct).
- **`release/X.Y.Z`** : partent de `develop` pour préparer une version (gel, bump de version,
  changelog) → merge dans `main` (**tag**) **puis** back-merge dans `develop`.
- **`hotfix/X.Y.Z`** : partent de `main` pour un correctif urgent de prod → merge dans `main` (**tag**)
  **puis** dans `develop`.

**Règles :**

1. Toute modification passe par une branche puis une **Pull Request** (utiliser la skill `/create-pr`).
   Jamais de push direct sur `main` **ni** sur `develop` (règle globale : pas de push direct sur les
   branches partagées).
2. **Versionnage sémantique** `MAJEUR.MINEUR.CORRECTIF` (tags **sans** préfixe `v`). La prod est fixée à **`1.0.0`**.
3. **Commits et documentation en français** ; **jamais** de trailer `Co-Authored-By` (préférence de longue date).
4. **Déploiement** : on ne déploie que depuis `main`, après merge d'une release/hotfix, CI verte et
   feu vert explicite (voir la garde de déploiement dans `AGENTS.md`). `scripts/deploy-files.sh`
   refuse de déployer si `main` n'est pas synchro et la CI verte.

> Cette section **remplace** l'ancienne règle « commit direct sur `main` » : elle n'a plus cours.

### Conventions de Pull Request

- **Titre et description en anglais** (le reste — commits, code, documentation — reste en français ;
  cette règle ne concerne que la PR elle-même). Le titre suit le format *conventional commit* et sert
  de titre au commit de squash au merge.
- **Assignée à son auteur** (assignee = la personne qui ouvre la PR).
- **Labellisée selon son type** : `bug` (correctif), `enhancement` (nouveauté), `documentation`
  (doc seule), etc. — choisir le(s) label(s) qui existe(nt) dans le dépôt.
- Ouverte via la skill `/create-pr`, qui applique ces conventions.

## Architecture cible

Le code PHP métier de LuziApi suit une **architecture hexagonale**, avec un **DDD pragmatique**.
Les nouveaux développements métier significatifs doivent être placés sous `src/`, organisés par
contexte fonctionnel, puis chargés avec l'autoload PSR-4 de Composer.

Le thème contient encore du code procédural historique dans `inc/`. Cet existant doit être migré
progressivement, dans un chantier dédié et sans régression fonctionnelle. Ne pas prolonger cette
architecture historique pour une nouvelle fonctionnalité métier importante.

Les petits hooks purement WordPress de présentation ou de configuration peuvent rester simples.
En revanche, toute règle concernant les commandes, paiements, encaissements, clients, stocks,
notifications ou obligations légales appartient au cœur applicatif.

## Organisation attendue

```text
www/wp-content/themes/luziapi/
├── src/
│   ├── <Contexte>/
│   │   ├── Domain/
│   │   ├── Application/
│   │   ├── Infrastructure/
│   │   ├── UserInterface/
│   │   └── Bootstrap/
│   └── Shared/
├── resources/
│   └── views/
├── assets/
├── templates/
└── functions.php
```

Les noms exacts peuvent évoluer avec le domaine, mais les responsabilités et le sens des
dépendances doivent rester explicites.

### Domain

Le domaine contient les entités, agrégats, objets-valeur, services et exceptions métier.

- aucune dépendance à WordPress, WooCommerce, Timber, Twig, `$wpdb` ou une fonction globale du
  CMS ;
- pas de HTML, de hook ni de lecture directe de requête HTTP ;
- invariants métier protégés par les objets eux-mêmes ;
- montants manipulés sans flottants, de préférence en centimes via un objet-valeur ;
- temps et identifiants représentés explicitement lorsque leur sens métier le justifie.

### Application

La couche Application orchestre les cas d'utilisation.

- commandes pour les écritures, requêtes pour les lectures ;
- DTO dédiés aux entrées et sorties ;
- dépendances reçues par injection de constructeur ;
- utilisation de ports pour l'horloge, les dépôts, les transactions, le cache et les exports ;
- aucune règle métier importante dans un contrôleur WordPress.

### Infrastructure

La couche Infrastructure implémente les ports avec les outils concrets du site.

- WooCommerce et HPOS sont des adaptateurs, pas le domaine ;
- utiliser les objets CRUD WooCommerce, `wc_get_order()` et `wc_get_orders()` ;
- ne pas interroger directement les tables internes des commandes WooCommerce ;
- isoler `$wpdb`, les options WordPress, les transients et les appels à des services externes ;
- convertir les objets WooCommerce vers les objets ou DTO internes dans des mappers dédiés.

### UserInterface

Cette couche contient les adaptateurs entrants : administration WordPress, formulaires, routes
REST ou, à terme, suivi public des commandes.

- vérifier les capacités et les nonces à l'entrée ;
- valider et normaliser les données avant d'appeler un cas d'utilisation ;
- échapper les sorties au plus près du rendu ;
- garder les contrôleurs minces ;
- ne pas contourner l'Application pour écrire directement dans un dépôt.

### Bootstrap

Le bootstrap est le point de composition. Il instancie les adaptateurs et les injecte dans les cas
d'utilisation, puis enregistre les hooks WordPress nécessaires.

Ne pas ajouter de conteneur d'injection de dépendances sans besoin démontré : l'assemblage manuel
par constructeurs est privilégié.

## Composer et namespaces

L'autoload applicatif doit être déclaré dans le `composer.json` du thème :

```json
{
  "autoload": {
    "psr-4": {
      "LuziApi\\": "src/"
    }
  }
}
```

- namespaces et chemins respectent PSR-4 ;
- le code des classes reste en anglais ; la documentation et les messages destinés à
  l'administration restent en français ;
- chaque fichier PHP active `strict_types=1` ;
- `functions.php` reste un bootstrap minimal et ne devient pas un catalogue de nouveaux
  `require_once` ;
- après toute modification de l'autoload, régénérer et tester l'autoloader Composer ;
- ne jamais modifier manuellement les fichiers générés sous `vendor/composer/`.

## DDD pragmatique

Le DDD sert à rendre le métier lisible, pas à multiplier artificiellement les classes.

- utiliser le vocabulaire réel de LuziApi dans les modèles et cas d'utilisation ;
- identifier les contextes avant de partager un modèle ;
- préférer un objet-valeur lorsqu'une donnée porte une validation ou un comportement réel ;
- ne pas recréer tout WooCommerce dans le domaine : ne modéliser que ce dont LuziApi a besoin ;
- documenter toute décision structurante ou compromis important dans `docs/` ;
- éviter les dépendances circulaires entre contextes ; passer par des ports, identifiants ou DTO.

## Compatibilité WordPress et WooCommerce

- HPOS est la source de vérité des commandes en production ;
- toute extension du workflow doit préserver les statuts, mouvements de stock, e-mails et options
  de non-envoi existants ;
- une coordonnée client ne vaut jamais consentement marketing ;
- distinguer systématiquement notes privées et notes adressées au client ;
- stocker les données métier persistantes avec une stratégie de version et de migration ;
- ne jamais supprimer les données lors d'une désactivation ou d'un changement de thème sans une
  décision explicite et une procédure de sauvegarde.

## Tests et qualité

La forme du test suit la frontière architecturale :

- tests unitaires du Domain sans démarrer WordPress ;
- tests des cas d'utilisation avec doubles en mémoire ;
- tests d'intégration pour les adaptateurs WordPress, WooCommerce et base de données ;
- test E2E du workflow des commandes pour tout changement pouvant toucher statuts, stock,
  paiements ou e-mails ;
- PHPStan et PHP-CS-Fixer avant commit ;
- une régression corrigée doit recevoir un test lorsque cela est raisonnablement possible.

### Toute fonctionnalité est testée à tous les niveaux pertinents

Une nouvelle fonctionnalité (ou une correction) doit apporter **tous** les tests que son risque
justifie, sans en sauter un niveau :

1. **tests unitaires** de la logique pure (Domain, calculs, VO) ;
2. **tests d'intégration** avec doubles en mémoire pour les cas d'utilisation ;
3. **e2e d'intégration local** (`make e2e-<slug>-local`) dès que le comportement passe par le
   **chemin réel WordPress/WooCommerce** (soumission de formulaire admin, ordre/branchement des
   hooks, capabilities, nonce, `$_POST`, stock, e-mails) — ce que l'unitaire ne peut pas couvrir ;
4. **e2e prod** rejouable (`scripts/e2e-<slug>-prod.sh` + cible `make e2e-<slug>-prod`), isolé et
   auto-nettoyé, aucun e-mail réel. Modèle de référence : `tools/e2e-exclusion*` +
   `scripts/e2e-exclusion-prod.sh` (voir `AGENTS.md`).

### Les tests tournent en CI — la maintenir à jour

La CI (`.github/workflows/ci.yml`) exécute **PHP-CS-Fixer, PHPStan, PHPUnit, les tests JS et
toutes les suites e2e locales**. Le runner `scripts/e2e-ci.sh` **découvre automatiquement** les
cibles `make e2e-*-local` : ajouter un e2e = ajouter sa cible `-local` au `Makefile`, et il tourne
en CI sans autre modification. Si un changement sort de ce cadre (nouveau job, nouvelle
dépendance système, nouveau chemin de déclenchement), **modifier la CI en conséquence**. Le test
`tests/E2eWiringTest.php` garde ce câblage intègre (runner appelé par le workflow, cibles et
scripts référencés existants, chaque script prod a sa cible Make).

PHPUnit tourne sur une **matrice PHP** (plancher du thème `8.2` + version courante `8.3` ; à aligner
sur la version réellement en prod si elle diffère) et **mesure la couverture** (pcov, `--coverage-text`).
Le périmètre de couverture est déclaré dans `phpunit.xml.dist` (`<source>` : cœur DDD `src/`, fichiers
`inc/` réellement testés, mu-plugin newsletter) — pas tout `inc/`, pour un taux honnête. PHPStan est
au **niveau `max`** avec une baseline (`phpstan-baseline.neon`) qui gèle la dette existante : tout
nouveau code doit passer au max, et la baseline est à résorber progressivement (ne pas y ajouter de
lignes pour contourner une nouvelle erreur). Un job **Audit des dépendances** (`composer audit` +
`npm audit`) tourne en CI, **non bloquant** pour l'instant (visibilité des CVE ; à rendre bloquant
une fois la dette éventuelle traitée).

Les doubles de test appartiennent aux tests. Ne pas ajouter de conditions spécifiques aux tests
dans le code de production.

## Dépendances

Avant d'ajouter une dépendance :

1. vérifier qu'une fonction native de PHP, WordPress ou WooCommerce ne couvre pas déjà le besoin ;
2. évaluer maintenance, licence, poids, sécurité et impact sur le déploiement ;
3. privilégier une bibliothèque locale et versionnée plutôt qu'un CDN ;
4. expliquer la dépendance dans la documentation du chantier ;
5. mettre à jour le verrou Composer et vérifier le déploiement de `vendor/` lorsqu'elle concerne
   PHP.

## Avant de livrer

- relire `AGENTS.md` et la documentation métier concernée ;
- vérifier le sens des dépendances de l'architecture hexagonale ;
- préserver les fonctionnalités existantes ;
- exécuter les tests proportionnés au risque ;
- mettre à jour la documentation partagée ;
- ne jamais publier, pousser ou déployer sans respecter les confirmations prévues dans
  `AGENTS.md`.
