# ADR 0001 — Architecture hexagonale + CQRS (alignée sur hellobees)

- **Statut :** accepté
- **Date :** 2026-09-20
- **Portée :** issue #1 (migration du métier de `inc/` vers `src/`)
- **Références :** [`CONTRIBUTING.md`](../../CONTRIBUTING.md), projet hellobees
  (`docs/cqrs-architecture.md` + `CONTRIBUTING.md`)

## Contexte

Le métier PHP du thème est encore en grande partie procédural dans `inc/`. Le
dépôt possède déjà un `src/` hexagonal (autoload PSR-4 `LuziApi\ => src/`), mais
les conventions internes divergent d'un module à l'autre. L'objectif de l'issue #1
est de migrer `inc/` vers `src/` **sans régression de comportement** et
**par petits lots testables**, en calant l'architecture sur le projet hellobees
(même auteur, même style CQRS inspiré de centreon `src/App`).

Cet ADR fige les décisions structurantes pour que le code, la doc et les futures
contributions restent cohérents.

## Décisions

### 1. Bounded contexts : peu, et vraiment métier

Un bounded context = la frontière d'un **langage ubiquitaire cohérent**, pas une
couche technique. On retient **trois** contextes (+ un noyau partagé) :

- **`Shop`** (core) — toute la boutique : commandes, produits/stock, clients,
  encaissement/recettes, remises, retrait ⇄ livraison, e-mails transactionnels,
  CGV au checkout. Un seul langage. Il absorbe l'ex-module `Pilotage` (le tableau
  de bord « Pilotage » reste un **libellé d'interface**, pas un contexte),
  `OrderTracking` (vue publique d'une commande) et le métier extrait de `inc/`.
- **`Loyalty`** (supporting) — langage propre et volontairement distinct
  (`offert` ≠ `fidélité`). Ne communique avec `Shop` que par un port ACL, jamais
  en important son modèle.
- **`Newsletter`** (generic) — intégration Brevo (e-mail / SMS).
- **`Shared`** — noyau partagé (kernel), **pas** un contexte : briques génériques
  et stables jointly-owned. Aucun concept métier ni agrégat ici.

**Règle inter-contexte :** un contexte n'importe jamais le modèle d'un autre. Pour
atteindre un autre contexte, il définit **son propre** port (`Domain/Gateway/`,
Anticorruption Layer) et son exception ; seuls des primitives / value objects
traversent la frontière. Référence : hellobees `Production\Domain\Gateway\ApiaryGateway`.

### 2. Trois couches : Domain / Application / Infrastructure

On **n'utilise pas** de dossier `UserInterface` ni `Bootstrap` (présents
aujourd'hui dans certains modules — à réaligner). Motivation : c'est la forme de
hellobees et du courant dominant PHP-DDD (CodelyTV), et ça évite deux dossiers
peu lisibles.

- **`Domain/`** — agrégats, value objects, enums, services de domaine, événements,
  interfaces de dépôt (`Repository/` + `Criteria/`), ports ACL (`Gateway/`),
  exceptions. Aucune dépendance à WordPress, WooCommerce, Timber, Twig, `$wpdb`.
- **`Application/`** — cas d'usage : `Command/<Action>/` (écritures) et
  `Query/<Action>/` (lectures). Un DTO + un Handler par action.
- **`Infrastructure/`** — adaptateurs concrets, **entrants et sortants**, groupés
  **par techno d'abord** (`WooCommerce/`, `WordPress/`, `Brevo/`, `Http/`), avec un
  sous-niveau optionnel par nature (`Repository/`, `Subscriber/`, `Hook/`, `Admin/`).
  Les hooks WC, contrôleurs admin, métabox et le suivi public sont des adaptateurs
  **entrants** et vivent donc ici.

**Composition root :** un unique fichier `Infrastructure/<Context>ServiceProvider.php`
par contexte (câblage manuel, pas de conteneur DI), appelé depuis un `functions.php`
resté minimal.

### 3. CQRS sans presenter

Contrairement à centreon core, **pas de pattern presenter** : le handler expose un
seul `__invoke(Command|Query)` qui **retourne** son résultat.

- **Command (écriture)** : construit un nouvel agrégat (`XId::generate()` +
  `Clock::now()` dans le handler) ou `getById`-mute-`update` ; retourne l'agrégat.
  Les suppressions retournent `void`.
- **Query (lecture)** : `Show<Entity>` retourne une `<Entity>View` (`final readonly`,
  primitives, `fromEntity()`) ; `List<Plural>` retourne `Paginator<<Entity>View>`.

### 4. Identités typées

Chaque agrégat étend `AggregateRoot<XId>` et porte un id typé
`final readonly class XId extends AggregateRootId` (adossé à un `Uuid`), créé par
`XId::generate()`, lu par `id()`. Une référence **intra-contexte** utilise l'id
typé ; une référence **inter-contexte** un `Uuid`/primitive nu (ACL). Un agrégat ne
détient jamais un autre agrégat en objet — seulement son id.

### 5. Dépôts, listing et erreurs

- **Une interface de dépôt par agrégat** dans `Domain/Repository/` :
  `getById(XId): X` (**lève** `<X>NotFoundException`, pas de `find` nullable),
  `findAll(?<X>Criteria): Paginator` (unique point de listing),
  `insert` / `update` / `delete`.
- **Listing** via un `Domain/Repository/Criteria/<X>Criteria` (allow-list
  d'opérateurs + mapping de champs), au-dessus de la boîte à outils de `Shared`
  (`Searchable`/`Sortable`/`Paginable` + `Paginator`).
- **Erreurs** : `<X>NotFoundException` `final` étendant
  `Shared\Domain\Exception\AggregateNotFoundException` (elle-même
  `extends \RuntimeException`), avec `withId()`. Les violations d'invariant/VO
  lèvent des exceptions natives via `webmozart/assert` (`\InvalidArgumentException`)
  ou `\LogicException`. Pas de base d'exception maison.

### 6. Agrégats riches, invariants, événements

Aucun setter public : constructeurs nommés (`create`/`record`/`add`…), méthodes
intentionnelles, invariants dans le constructeur et chaque mutateur (`webmozart/assert`).
Événements de domaine `final readonly` sous `<Context>/Domain/Event/` étendant
`Shared\Domain\Event\DomainEvent` (émis, dispatch non câblé pour l'instant).

### 7. Conventions PHP / tests

- `declare(strict_types=1);` partout ; DTO / View / VO en `final readonly class`.
- Docblocks limités à `@throws`, `@phpstan-*` et les génériques utiles.
- **Tests** : un test de handler par handler (chemin heureux + not-found), pilotés
  par des doubles in-memory dans `tests/<Context>/Double/`, attributs PHPUnit.
- Les **e2e** WooCommerce/WordPress existants restent la preuve de non-régression du
  chemin réel.
- Portes de qualité : PHP-CS-Fixer, PHPStan niveau **max sans baseline**, PHPUnit,
  toutes vertes avant qu'un lot soit « fait ».

## Conséquences

- La migration avance **par lots** sur une branche unique (PR de l'issue #1), chacun
  vert en CI et sans changement de comportement (on déplace la règle, on ne la
  réécrit pas).
- Nouvelle dépendance : `webmozart/assert` (invariants). `ramsey/uuid` pour les ids
  typés (à confirmer selon le transitif disponible).
- Les modules `Loyalty` et `Newsletter` restent des contextes autonomes ; leur
  réalignement complet sur les ids typés / Criteria pourra se faire en PRs de suivi.
- `CONTRIBUTING.md` sera mis à jour en fin de parcours pour refléter ces décisions
  (notamment l'abandon des dossiers `UserInterface`/`Bootstrap`).

## Séquencement (lots de la PR #1)

0. **ADR + fondations** (ce document).
1. **Kernel `Shared`** (mirroir de hellobees : `AggregateRoot(Id)`, `Collection`,
   `Paginator` + Criteria, `AggregateNotFoundException`, `DomainEvent`, VO
   `Money`/`Uuid`/`Email`/`PhoneNumber`, `Clock`, helper `Wp`).
2. **Reshape `Shop`** en 3 couches (`Application/Port` → `Domain/Repository`+`Gateway`,
   `UserInterface/Admin` → `Infrastructure/WordPress/Admin`, `Bootstrap` →
   `Infrastructure/ShopServiceProvider`) + fix de la fuite
   `Loyalty/Domain/LoyaltyIdentity → Shop` via un Gateway ACL.
3. **Absorber `OrderTracking`** dans `Shop`.
4-8. **Migrer les règles pures de `inc/`** : Legal (CGV / règles fidélité), Delivery
   (validation de zone), Fulfillment (source + mode ⇄ statut), Notifications
   (e-mails de statut), échéance de paiement (BACS).
9. **`functions.php` minimal** + garde PHPStan « pas de WP dans `Domain/` » + mise à
   jour de la doc.
