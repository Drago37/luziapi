# Contributing to LuziApi

This document describes the project's technical conventions. It is intended for developers as well
as AI agents. Also read `AGENTS.md` before any intervention: it contains the collaboration,
publication and deployment rules specific to LuziApi.

## Git flow — GitFlow (MANDATORY since 1.0.0)

The project has been **in production since 1.0.0** (11 September 2026): the alpha/beta phase where
work was committed directly to `main` is **over**. From now on, **GitFlow is mandatory**, including
for AI agents. **Never commit or push directly to `main`.**

**Branches:**

- **`main`** = production. Receives ONLY merges from `release/*` or `hotfix/*`, and **every merge is
  tagged `X.Y.Z`** (without a `v` prefix). The HEAD of `main` is the deployed state. No direct commits.
- **`develop`** = integration. Base for all features; this is where day-to-day work accumulates
  between two releases.
- **`feature/<slug>`**: branch off `develop` and return to it through a **Pull Request** (never a direct merge).
- **`release/X.Y.Z`**: branch off `develop` to prepare a version (version bump, and **compose the
  `CHANGELOG.md` `[X.Y.Z]` section on this branch** from the PRs merged since the last tag —
  feature PRs do **not** edit the changelog) and open a PR to `main`. **Deploy to production from the still-open release branch**
  (`scripts/deploy-files.sh` allows `release/*`), then — **only after** the deployment succeeds and
  on an **explicit go-ahead** — merge into `main` (**tag**) and back-merge into `develop`.
- **`hotfix/X.Y.Z`**: branch off `main` for an urgent production fix, deploy from the hotfix branch,
  then merge into `main` (**tag**) and into `develop`.

> **The release PR stays open during deployment.** It is not merged automatically: deploying from
> the release branch keeps the frozen changelog available for the production write-up and lets us
> push fixes onto the branch if the deployment surfaces a problem. The merge into `main` + tag are a
> **separate, human-approved step after a successful deployment** — an agent asks and waits for the
> go-ahead, it never merges the release on its own.

> **The back-merge of `main` into `develop` is AUTOMATIC**: it is an integral part of closing out a
> release or a hotfix (`main` tagged → back to `develop`), it is **not** a change to be reviewed. An
> agent performs it **without asking for confirmation**, right after the tag:
> `git checkout develop && git merge --no-ff origin/main && git push origin develop`. Never leave
> `main` ahead of `develop`. This is the **only** exception to "no direct push to `develop`": it only
> reintroduces what was just tagged on `main`. **Deployment**, on the other hand, remains subject to
> an explicit go-ahead (see the deployment gate).

**Rules:**

1. Every change goes through a branch and then a **Pull Request** (use the `/create-pr` skill).
   Never push directly to `main` **or** to `develop` — **except** the automatic end-of-release/hotfix
   back-merge described above, the only exception.
2. **Semantic versioning** `MAJOR.MINOR.PATCH` (tags **without** a `v` prefix). Production is pinned at **`1.0.0`**.
3. **Agent and process docs (`AGENTS.md`, `CONTRIBUTING.md`, `CLAUDE.md`, `CHANGELOG.md`), commit
   messages and pull requests are in English**; business and technical documentation stays in French
   (`README.md`, `DEPLOIEMENT.md` and everything under `docs/`, including the nested README files).
   Code stays in English. **Never** a `Co-Authored-By` trailer (long-standing preference).
4. **Deployment**: we deploy from `main`, or from a still-open `release/*` / `hotfix/*` branch
   (see the release flow above), with the branch pushed (in sync with its origin), a green CI on
   HEAD and an **explicit go-ahead** (see the deployment gate in `AGENTS.md`).
   `scripts/deploy-files.sh` enforces this and refuses otherwise.

> This section **replaces** the former "direct commit on `main`" rule: it no longer applies.

### Pull Request conventions

- **Title and description in English** (the same English-only policy applies to commit messages and
  the agent/process docs; business and technical documentation stays in French). The title follows the
  *conventional commit* format and serves as the title of the squash commit at merge time.
- **Assigned to its author** (assignee = the person who opens the PR).
- **Labelled according to its type**: `bug` (fix), `enhancement` (new feature), `documentation`
  (docs only), etc. — pick the label(s) that exist(s) in the repository.
- Opened via the `/create-pr` skill, which applies these conventions.

## Target architecture

LuziApi's business PHP code follows a **hexagonal architecture**, with **pragmatic DDD**.
Significant new business developments must be placed under `src/`, organized by bounded context,
then loaded with Composer's PSR-4 autoloader.

The structuring decisions (bounded contexts, three layers, CQRS conventions) are recorded in
[`docs/architecture/0001-architecture-hexagonale-cqrs.md`](docs/architecture/0001-architecture-hexagonale-cqrs.md),
aligned on the sibling hellobees project. The current contexts are **Shop** (core), **Loyalty**,
**Newsletter** and **OrderTracking**, plus the **Shared** kernel.

The theme still contains legacy procedural code in `inc/`. This legacy code must be migrated
progressively, in a dedicated effort and without functional regression. Do not extend this legacy
architecture for an important new business feature.

Small, purely WordPress presentation or configuration hooks can stay simple. On the other hand,
any rule concerning orders, payments, receipts, customers, stock, notifications or legal
obligations belongs to the application core.

## Expected layout

```text
www/wp-content/themes/luziapi/
├── src/
│   ├── <Context>/
│   │   ├── Domain/
│   │   ├── Application/
│   │   └── Infrastructure/
│   └── Shared/
├── resources/
│   └── views/
├── assets/
├── templates/
└── functions.php
```

The exact names may evolve with the domain, but the responsibilities and the direction of
dependencies must remain explicit.

### Domain

The domain contains the entities, aggregates, value objects, services and business exceptions.

- no dependency on WordPress, WooCommerce, Timber, Twig, `$wpdb` or any global CMS function;
- no HTML, no hooks and no direct reading of the HTTP request;
- business invariants protected by the objects themselves;
- amounts handled without floats, preferably in cents via a value object;
- time and identifiers represented explicitly when their business meaning warrants it.

### Application

The Application layer orchestrates the use cases.

- commands for writes, queries for reads;
- one handler per use case, whose `__invoke` **returns** its result (no presenter, no bus);
- dedicated DTOs for inputs and outputs;
- dependencies received through constructor injection;
- the interfaces they depend on live in the **domain** — aggregate repositories in
  `Domain/Repository/`, system or cross-context access (clock, external services, another context)
  as `Domain/Gateway/` ports (an Anticorruption Layer);
- no important business rule in a WordPress controller.

### Infrastructure

The Infrastructure layer holds every adapter — both **driven** (implementing the domain's ports)
and **driving** (the inbound WordPress entry points) — grouped by technology first
(`WooCommerce/`, `WordPress/`, `Brevo/`, `Http/`).

- WooCommerce and HPOS are adapters, not the domain;
- use the WooCommerce CRUD objects, `wc_get_order()` and `wc_get_orders()`;
- do not query the internal WooCommerce order tables directly;
- isolate `$wpdb`, WordPress options, transients and calls to external services;
- convert WooCommerce objects into internal objects or DTOs in dedicated mappers;
- the **inbound** adapters (admin controllers, WooCommerce hooks, REST routes, the public order
  tracking) live here too: they check capabilities and nonces, validate input, call a use case and
  render — thin, with no business rule;
- the **composition root** is a single `Infrastructure/<Context>ServiceProvider.php` that wires the
  concrete adapters into the handlers and registers the WordPress hooks (manual assembly, no DI
  container without a demonstrated need). `functions.php` only calls each provider's `boot()`.

## Composer and namespaces

The application autoload must be declared in the theme's `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "LuziApi\\": "src/"
    }
  }
}
```

- namespaces and paths follow PSR-4;
- the class code stays in English; the documentation and messages intended for the administration
  stay in French;
- every PHP file enables `strict_types=1`;
- `functions.php` remains a minimal bootstrap and does not become a catalogue of new
  `require_once`;
- after any change to the autoload, regenerate and test the Composer autoloader;
- never manually edit the generated files under `vendor/composer/`.

## Pragmatic DDD

DDD serves to make the business readable, not to artificially multiply classes.

- use LuziApi's real vocabulary in the models and use cases;
- identify the bounded contexts before sharing a model;
- prefer a value object when a piece of data carries real validation or behavior;
- do not recreate all of WooCommerce in the domain: model only what LuziApi needs;
- document any structuring decision or important trade-off in `docs/`;
- avoid circular dependencies between contexts; go through ports, identifiers or DTOs.

## WordPress and WooCommerce compatibility

- HPOS is the source of truth for orders in production;
- any extension of the workflow must preserve the existing statuses, stock movements, e-mails and
  no-send options;
- a customer contact detail never amounts to marketing consent;
- always distinguish between private notes and notes addressed to the customer;
- store persistent business data with a versioning and migration strategy;
- never delete data on deactivation or a theme change without an explicit decision and a backup
  procedure.

## Tests and quality

The form of the test follows the architectural boundary:

- unit tests of the Domain without booting WordPress;
- use-case tests with in-memory doubles;
- integration tests for the WordPress, WooCommerce and database adapters;
- E2E test of the order workflow for any change that could touch statuses, stock, payments or
  e-mails;
- PHPStan and PHP-CS-Fixer before committing;
- a fixed regression must receive a test whenever it is reasonably possible.

### Every feature is tested at all relevant levels

A new feature (or a fix) must bring **all** the tests its risk warrants, without skipping a level:

1. **unit tests** of the pure logic (Domain, computations, VOs);
2. **integration tests** with in-memory doubles for the use cases;
3. **local integration e2e** (`make e2e-<slug>-local`) as soon as the behavior goes through the
   **real WordPress/WooCommerce path** (admin form submission, hook order/wiring, capabilities,
   nonce, `$_POST`, stock, e-mails) — what unit tests cannot cover;
4. **replayable production e2e** (`scripts/e2e-<slug>-prod.sh` + `make e2e-<slug>-prod` target),
   isolated and self-cleaning, no real e-mails. Reference model: `tools/e2e-exclusion*` +
   `scripts/e2e-exclusion-prod.sh` (see `AGENTS.md`).

### The tests run in CI — keep it up to date

The CI (`.github/workflows/ci.yml`) runs **PHP-CS-Fixer, PHPStan, PHPUnit, the JS tests and all
the local e2e suites**. The `scripts/e2e-ci.sh` runner **automatically discovers** the
`make e2e-*-local` targets: adding an e2e = adding its `-local` target to the `Makefile`, and it
runs in CI with no other change. If a change falls outside this framework (new job, new system
dependency, new trigger path), **modify the CI accordingly**. The `tests/E2eWiringTest.php` test
keeps this wiring intact (runner called by the workflow, referenced targets and scripts exist,
each production script has its Make target).

PHPUnit runs on a **PHP matrix** (theme floor `8.2` + current version `8.3`; to be aligned with
the version actually in production if it differs) and **measures coverage** (pcov, `--coverage-text`).
The coverage scope is declared in `phpunit.xml.dist` (`<source>`: the DDD core `src/`, the `inc/`
files actually tested, the newsletter mu-plugin) — not all of `inc/`, for an honest rate. PHPStan is
at **level `max`** with **no baseline**: the whole theme passes at max, and it must stay that way —
fix the underlying type instead of introducing a baseline or `@phpstan-ignore` to work around a new
error. A **Dependency audit** job (`composer audit` + `npm audit`) runs in CI,
**non-blocking** for now (CVE visibility; to be made blocking once any debt is addressed).

Test doubles belong to the tests. Do not add test-specific conditions to the production code.

## Dependencies

Before adding a dependency:

1. check that a native PHP, WordPress or WooCommerce function does not already cover the need;
2. assess maintenance, license, weight, security and impact on deployment;
3. prefer a local, versioned library over a CDN;
4. explain the dependency in the effort's documentation;
5. update the Composer lock and verify the deployment of `vendor/` when it concerns PHP.

## Before shipping

- re-read `AGENTS.md` and the relevant business documentation;
- check the direction of the hexagonal architecture's dependencies;
- preserve the existing features;
- run the tests proportionate to the risk;
- update the shared documentation;
- never publish, push or deploy without respecting the confirmations set out in `AGENTS.md`.
