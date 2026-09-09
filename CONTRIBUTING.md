# Contribuer à LuziApi

Ce document décrit les conventions techniques du projet. Il s'adresse aux développeurs comme aux
agents IA. Lire également `AGENTS.md` avant toute intervention : il contient les règles de
collaboration, de publication et de déploiement propres à LuziApi.

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
