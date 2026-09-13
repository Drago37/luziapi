# Instructions for agents (LuziApi)

This file is the repository's working memory: it gathers the collaboration rules and the
knowledge about the project that cannot be deduced from the code. It is meant to be read by
any code assistant (Codex, Claude Code, etc.).

> Agent- and process-facing docs are written in **English**: this file (AGENTS.md),
> [`CONTRIBUTING.md`](CONTRIBUTING.md), [`CLAUDE.md`](CLAUDE.md) and `CHANGELOG.md`. **Commit
> messages and pull requests are in English** too. **Business and technical documentation stays
> in French**: `README.md`, `DEPLOIEMENT.md` and everything under `docs/` (production state,
> business processes, loyalty, CGV, test procedures, SMS templates, etc.), plus the nested
> README files (`prod-mu-plugins/README.md`, `www/wp-content/themes/luziapi/README.md`). The
> **code** stays in English.
>
> Before modifying the code, also read **[`CONTRIBUTING.md`](CONTRIBUTING.md)**: it defines the
> hexagonal architecture, the pragmatic DDD and the expected PHP/Composer conventions.

---

## 1. Collaboration rules (to be respected systematically)

### Confirm before any publication

For **any action visible from the outside** — publishing a WordPress article, sending a Brevo
campaign / newsletter, editing a page in production, deploying — : first build the content
**with** the user, show the rendering or the final result, then **wait for explicit
confirmation** before executing. Never put anything online nor trigger a send on your own
initiative.

_Why:_ the user wants to keep control and review beforehand. Stated explicitly: "I want to work
on it with you first, as always… you must have my confirmation before publishing afterwards."

This also applies to the **newsletter auto-send**, triggered by the first publication of an
article (see [docs/prod-o2switch.md](docs/prod-o2switch.md)): publishing an article means
sending an e-mail + an SMS to the entire list.

### Never remove a feature without saying so

**Never delete, remove or disable an existing feature** without asking for confirmation
beforehand — **even as a mere side effect** of another change. By default, preserve what exists:
carry over / reimplement the feature in the new context rather than abandoning it.

_Why:_ when the cart button was moved from the header to the floating buttons, the drop-down
mini-cart (deemed handy) was removed in the process without warning. Badly received.

### Git: GitFlow mandatory (since 1.0.0)

The project has been **in production since `1.0.0`** (11 September 2026): the alpha/beta phase
where we committed directly on `main` is **over**. **GitFlow is now mandatory, including for
agents.** **Never commit or push directly on `main` or on `develop`.**

- `main` = production (deployed state, tag `X.Y.Z` — without the `v` prefix — at each release); it
  only receives merges from `release/*` or `hotfix/*`. `develop` = integration (base for features).
- Any change → `feature/*` branch (from `develop`) → **Pull Request** via `/create-pr`;
  release `release/X.Y.Z` (develop → PR to main → **deploy from the still-open release branch** →
  merge + tag on explicit go → back to develop); emergency `hotfix/X.Y.Z` (main → deploy →
  tag → develop). **Full and up-to-date details in [`CONTRIBUTING.md`](CONTRIBUTING.md) — to be
  read and respected.**
- **The release PR stays open during deployment; it is NOT merged automatically.** Deploy to prod
  from the open `release/*` branch (keeps the changelog available, allows fixes on the branch), then
  merge into `main` + tag only **after** a successful deployment and on an **explicit go-ahead** —
  the agent asks and waits, it never merges the release on its own.
- **Back-merge `main` → `develop` = AUTOMATIC, without asking.** Right after tagging `main`
  (end of release/hotfix), immediately carry `main` back into `develop` (`git checkout develop && git merge
  --no-ff origin/main && git push origin develop`). This is the very point of GitFlow, not a decision to
  validate — never leave `main` ahead of `develop`. The only exception to "no direct push on
  `develop`". **Deployment**, on the other hand, keeps its explicit green light.
- Commit messages and pull requests are in **English**; **never** a `Co-Authored-By:` trailer
  (explicit preference, a commit was already refused and the history cleaned to remove it).
- **Pull Requests: title and description in English**, **assigned to their author**, **labeled
  by type** (`bug`, `enhancement`, `documentation`…). Details in
  [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Do not push without an explicit request; we only deploy from `main` after merging a release,
  with the CI green (see the deployment gate below).

### Deploy = explicit green light

"Deploy" is a publication action: it requires clear agreement, like everything else.

### Deployment gate: code pushed + CI green (blocking)

**Never deploy unless the code is pushed AND the CI is green on `HEAD`.** Deployment runs from
`main`, or from a still-open `release/*` / `hotfix/*` branch (release flow above). Imposed order:
commit → push the branch → wait for the CI to be green → and only then, on an explicit green light,
deploy. A local check does not replace the CI. `scripts/deploy-files.sh` applies this gate
automatically and **refuses** to deploy otherwise (clean tree, current branch is
`main`/`release/*`/`hotfix/*` and in sync with its origin, CI run "Qualité du thème" `success` on
the current SHA).

_Why:_ rule set explicitly — "the code must be pushed and the CI green mandatorily, otherwise we
block the deployment". Convention: **`main` = the up-to-date production** (deployed state), tagged
`X.Y.Z` at each release (GitFlow is now in place; the first tag is `1.0.0`).

### After each deployment: log it (mandatory, without being asked)

**Every deployment must be logged in [docs/prod-o2switch.md](docs/prod-o2switch.md)** right
away, without waiting to be asked: commit(s) deployed, files, result of the checks (SHA, OPcache,
live control) and what changes on the production side. Also update the project memory if useful.
Never leave a deployment undocumented.

_Why:_ explicit request from the user — "it must be done after each deployment, having to remind
me is tiresome". It is an expected reflex, not an option.

### Keep this documentation up to date

These files **are** the project's memory: it is the only one shared between machines and between
assistants. As soon as a session surfaces something worth remembering — a user preference, a
server pitfall, a configuration decision, a change in the production state — write it here right
away, without waiting to be asked:

- behavior rule, git flow, deployment method, server pitfall → **`AGENTS.md`**;
- production state → **`docs/prod-o2switch.md`**.

Never write any credential here (see § 6). `CLAUDE.md` is only a pointer to this file:
do not duplicate anything in it.

---

## 2. Project map

- **README.md** — repository structure and local installation (Docker, `make install`).
- **CONTRIBUTING.md** — target architecture, hexagonal separation, DDD, Composer and quality
  conventions. To be read before any change to the code.
- **CHANGELOG.md** — notable changes per version (Keep a Changelog format); updated during each
  release.
- **DEPLOIEMENT.md** — going live and updating the theme (`make deploy`, FTPS).
- **docs/prod-o2switch.md** — production state, functional architecture, server pitfalls.
  To be read before any intervention touching production.
- **docs/processus-metier-commandes.md** — the real process for selling and handling orders,
  diagram, automations, notifications and configuration discrepancies.
- **docs/fidelite.md** — loyalty program ("15 pots achetés, le 16e offert" / buy 15 jars, the
  16th free; a jar expires after 2 years — see `LoyaltyProgress::POTS_PER_REWARD` /
  `POT_LIFETIME_YEARS`), architecture of the `src/Loyalty/` module and tracking of issue #4.
- **docs/modeles-sms-brevo.md** — SMS templates.
- **docs/** — printed and visual materials:
  - `print/` — brochure, flyer and business card. Two variants per document: without a suffix
    (intended for the web) and `-print` (bleed / profile for the printer).
  - `sources/` — zipped editable files from which the `print/` PDFs are produced.
  - `etiquettes/` — jar labels; `social/` — social network visuals.
- **prod-mu-plugins/** — the production mu-plugins, versioned here but **deployed separately**
  (the FTP account does not see them, see below).

### Downloadable brochure and flyer

`docs/print/` is the **source**; the versions published on the site are **copies** in
`www/wp-content/themes/luziapi/assets/docs/` (web variants only, not the `-print` ones). The
two easily drift out of sync — observed: the theme and production were serving an older version
than the sources. When one of these PDFs changes:

1. copy `docs/print/LuziApi-{brochure,flyer}.pdf` back into `assets/docs/`;
2. correct the announced size in the "Documents" column of
   `templates/partials/footer.twig` — it is **hard-coded** (convention: base 1024,
   whole `Ko`, `Mo` to one decimal);
3. deploy the 3 files via targeted FTPS (§ 3) and check the served `content-length`.

Only the **theme** `www/wp-content/themes/luziapi/` is versioned: the WordPress core, plugins and
media are provided by the host or by Docker.

### Versioning the CGV without losing the customer's proof

The public text of the CGV (terms and conditions of sale) lives in
`templates/page-conditions-generales-de-vente.twig`. Its version is declared in
`inc/commerce-legal.php` and has an immutable PDF of the same vintage in
`assets/docs/` (for example `LuziApi-CGV-2026-09-07.pdf`). When evolving it:

1. change the version constant and create a **new** PDF name;
2. never overwrite or delete a PDF already accepted by customers;
3. check that the first confirmation e-mail attaches the new version;
4. keep the version and the acceptance timestamp in the order;
5. deploy the new PDF with the corresponding PHP/Twig files, then clear the OPcache.

The mediator's agreements and attestations are confidential and stay out of the repository. Only
their public contact details needed for the CGV are versioned.

### Naming files intended for a printer

For a variant prepared for a print website, put only the site's name as the final suffix: for
example `LuziApi-carte-recto-saxoprint.pdf`. Do not add technical details to the name (`x4`,
`vectorise`, etc.), which are checked in the file itself.

Tests: `phpunit.xml.dist` + `tests/` (pure logic). CI: PHPStan + CS-Fixer on the theme
(`.github/workflows/ci.yml`). **End-to-end** test of the order process (statuses,
e-mails, payment due date, cancellation, stock): `tools/e2e-orders.php` + doc
[docs/tests-e2e-commandes.md](docs/tests-e2e-commandes.md) — to be replayed (local `make e2e-local`
or prod) at each change of the order workflow.
The customer tracking without an account additionally has its local integration test
`make e2e-tracking-local` and its documentation in
[docs/tests-suivi-commandes.md](docs/tests-suivi-commandes.md).
Loyalty (`src/Loyalty/`) has its unit tests `tests/Loyalty/`, `tests/Pilotage/`
and its local integration tests `make e2e-loyalty-local` (jars/benefits),
`make e2e-discount-local` (thank-you discount) and `make e2e-vente-loyalty-local`
(real Sale path: free gift + loyalty + discount + stock), plus a replayable
**end-to-end test on production** `make e2e-loyalty-prod` (isolated test product +
test order, status "Terminée" (Completed) via `set_status` so no e-mail nor receipt,
everything cleaned up) — see [docs/fidelite.md](docs/fidelite.md).
The "Exclure de la fidélité" (Exclude from loyalty) checkbox on the order sheet additionally has
its integration e2e `make e2e-exclusion-local` and its **end-to-end test on production**
`make e2e-exclusion-prod`: they drive the **real admin path** (administrator
context + nonce + `$_POST`, call to `luziapi_save_admin_order_workflow`, which
sets the meta then emits `luziapi_loyalty_exclusion_changed` recalculated by the subscriber),
and also check that the hooks are wired (isolated, no e-mail, everything cleaned up).
The **addition of a free jar to an existing order** ("Ajouter un pot offert" (Add a free jar)
block on the order sheet, gesture **or** loyalty) likewise has `make e2e-offered-pot-local`
and `make e2e-offered-pot-prod`: they drive the **real admin path**
(`luziapi_save_admin_order_workflow` → adding a line at 0 € → loyalty recalculation via
`luziapi_loyalty_order_lines_changed`) and check that the line is free,
that the **order amount does not change** (receipt intact), that the **stock is
decremented** for the new item only, that **loyalty consumes a benefit** (bounded to the
available amount, second attempt refused), plus the wiring of the hooks (isolated, no e-mail,
everything cleaned up).
The **newsletter auto-send** (mu-plugin `luziapi-newsletter-autosend`) likewise has
`make e2e-newsletter-local` and `make e2e-newsletter-prod`: they check that the
publication **schedules** (without sending), that a re-edit does not re-schedule, and that
the deferred execution goes through the right channel — **by intercepting the Brevo calls**
(`pre_http_request`) so that **no e-mail/SMS goes out** (the only output channel
is short-circuited), the test article being deleted and the event unscheduled. The local
target first installs the mu-plugin into `www/wp-content/mu-plugins/` (absent in dev).
The **delivery zone validation** at checkout has `make e2e-delivery-zone-local` /
`e2e-delivery-zone-prod`: they run the real hook `woocommerce_after_checkout_validation`
(rejection of a free delivery outside Bléré/Luzillé 37150) — read-only, no order,
no e-mail. The **automatic receipt** at the transition to "Terminée" (Completed) also has its prod
variant `make e2e-receipt-prod` (in addition to the local one): order isolated with `set_status`, subscriber
invoked with the **unaudited** repository (no writing to the activity journal), everything cleaned up.
The **removal of the receipt on trashing / deletion of an order** has
`make e2e-orphan-receipt-local` / `e2e-orphan-receipt-prod`: they create an order
with a receipt, move it to the **trash** (`woocommerce_trash_order`) then to
**deletion** (`woocommerce_before_delete_order`), and check that the subscriber
`WooCommerceOrphanReceiptSubscriber` did remove the receipt from the register (isolated, everything
cleaned up). _Reason:_ a deleted order that kept its receipt inflated the amount collected
above the amount sold (observed: 132 € of orphan receipts in 2026).

> **Cover in e2e what unit tests cannot.** A feature whose
> behavior goes through the **real WordPress/WooCommerce path** (submission of an
> admin form, order/wiring of the hooks, capabilities, nonce, `$_POST`) is
> not coverable by the "pure logic" tests of `tests/`. We cover it with an integration e2e
> that replays this path: a shared core `tools/e2e-*.php`, a local WP-CLI
> wrapper (`make …-local`) and a token-based prod wrapper (`scripts/…-prod.sh`,
> `make …-prod`) — isolated and self-cleaning, no e-mail. Reference model:
> `tools/e2e-exclusion*.php` + `scripts/e2e-exclusion-prod.sh`.

**Logging.** Shared PSR-3 logger `luziapi_logger()` (Monolog, `inc/logger.php`), in
**fingers-crossed** mode: each request buffers everything but writes to `wp-content/luziapi-logs/prod.log`
**only if an `ERROR` occurs** (the buffer then goes out as context) — a healthy request writes nothing.
`luziapi_register_error_handler()` (called in `functions.php`) routes PHP errors + uncaught
exceptions + fatals into Monolog and turns off `log_errors`/`display_errors`: **we no longer use the
native `error_log`** (it had reached 16 GB, flooded by WooCommerce warnings — see
docs/prod-o2switch.md). Processors: Web (URL/method/IP/referer/user-agent), Introspection, memory.
Folder outside the theme, protected by `.htaccess`, not versioned, not deployed (created at runtime); to read
the production logs, go through the token-based script (§ 4). Complemented by a **daily rotation**
(`RotatingFileHandler`, 14 days), an **e-mail alert on `ERROR`** (`WordPressMailerHandler`,
throttled to 1 message / 2 min) and the **fix at the source** of the WooCommerce warning
"Undefined array key state" (filter `woocommerce_cart_shipping_packages` in `inc/woocommerce.php`,
which guarantees `country/state/postcode/city/address` without changing the zone matching). Issue #5
is **closed, these three suites being done**.

---

## 3. Deployment — choosing the right method

### A few theme files change, without a new Composer dependency

→ **`scripts/deploy-files.sh`** (targeted FTPS upload), not `make deploy`.

The script does all the targeted work end to end: deployment gate (§ 1 — code pushed + CI green),
computation of the `git diff <base>..HEAD` delta (base = last deployment recorded in the local
unversioned file `scripts/.last-deploy`, or passed as an argument), upload of only the theme's
code files, OPcache cleared + SHA-256 comparison local ↔ prod via the token-based script, then
`post-deploy-check.sh`. In case of doubt, the manual procedure remains below.

_Why:_ `make deploy` runs `composer-prod` **in the `wordpress` Docker container** (so it
fails if Docker is not started: "service wordpress is not running"), then an
`lftp mirror -R --delete` of **the whole theme, `vendor/` included** → thousands of files over
FTPS, slow and prone to timeouts (observed: timeout at 2 min just at the listing). Disproportionate
for 2-3 files.

Procedure:

1. Read the `DEPLOY_FTP_*` in `.env.local`. The account (`luziapi-deploy@luziapi.fr`) is
   **chrooted to the theme folder**: `DEPLOY_FTP_PATH=.` — the FTP root **is**
   `wp-content/themes/luziapi/`.
2. Upload each file into its subfolder, with
   `ftp:ssl-force true; ssl:verify-certificate yes; passive-mode true`:
   ```
   lftp … -e "put -O <FTP_PATH>/inc inc/shop.php; put -O <FTP_PATH>/assets/css assets/css/main.css; bye"
   ```
3. If **PHP files** changed → **clear the OPcache** (see § 4). CSS/Twig only: unnecessary.
4. **Verify**: SHA-256 local vs server (`hash_file` via the token-based script) **and** the real
   HTTP rendering (curl + grep on the pages). Beware of the PowerBoost cache: also test without a
   cache-buster.

### A Composer dependency changes (new `require`)

You must regenerate `vendor/` in the prod version **and deploy the _complete_ vendor**, not just the
new package + the autoload. `composer` regenerates an **autoload consistent with the whole local vendor**:
if prod has an **incomplete** vendor (aftermath of an interrupted deployment), the new autoload will
`require` files hard (e.g. `react/promise` in _files-autoload_) that are **absent from prod** → fatal,
site in 500. Safe procedure:

1. `composer install --no-dev --optimize-autoloader` (in the container: `docker compose exec … `),
   for a **prod-only** vendor (~500 files, without PHPStan/PHPUnit/CS-Fixer).
2. FTPS mirror of the **entire vendor** (`mirror -R --no-perms`, without `--delete`) — not just the delta.
3. Clear the OPcache, then `make deploy-check-live` (uncached URLs).
4. Restore the dev dependencies locally: `composer install`.

Observed on 10 September 2026 while deploying Monolog: prod threw a 500 because its vendor had lost
`react/promise` (partial deployment of the 9th). Details: [docs/prod-o2switch.md](docs/prod-o2switch.md).
`make deploy` regenerates and mirrors too, but its full theme mirror is fragile to interruption
(§ above): prefer the `--no-dev` regeneration + targeted mirror of `vendor/` only.

> **Dev/test artifacts never deployed.** The `make deploy` mirror excludes, via the
> `DEPLOY_EXCLUDES` variable of the `Makefile`, everything that does not serve the runtime: `tools/`, `tests-js/`,
> `node_modules/`, `package.json` / `package-lock.json`, CS-Fixer / PHPStan config, `README.md`.
> Add any new tool or test folder to this list. (Make pitfall fixed in passing: a
> glob containing `#` must be escaped `\#`, otherwise make comments out the rest of the line.)

### A mu-plugin changes

The FTP account is chrooted to the theme and does **not** see `wp-content/mu-plugins/`. Deployment
via **token-based script** (§ 4): FTPS push of a `_deploy.php` + base64 payload into the theme
folder, HTTPS call with the token, writing into `WPMU_PLUGIN_DIR` with a
`.bak-<timestamp>` backup and `opcache_reset()`, then deletion of the script.

---

## 4. Running an action on the server (neither SSH nor cPanel)

The **token-based PHP script** technique:

1. Drop a small token-protected PHP script into the theme folder (`lftp put`).
2. Call it over HTTPS: `/wp-content/themes/luziapi/_xxx.php?k=TOKEN`. The script does
   `require ../../../wp-load.php` to have all of WordPress / WooCommerce available.
3. **Delete it** once the operation is finished.

This allows: creating/updating content via the WP/WooCommerce API, reading options or logs
(`../../../error_log`), writing into `WPMU_PLUGIN_DIR`, calling `opcache_reset()`, comparing file
fingerprints.

> `wp-config.php` is not editable via FTP → go through a **mu-plugin** to define
> constants.

### Server pitfalls to know

- **OPcache** serves the old version of a modified PHP file. After any deployment of theme PHP
  (`inc/*.php`, `functions.php`…), call `opcache_reset()`. Observed: a new WooCommerce
  hook stayed invisible as long as the OPcache was not cleared. **This also applies to
  `make deploy`.**
- **WooCommerce orders list**: production uses **HPOS**. With WordPress 7.1 and
  WooCommerce 10.8.1, clicking a selection checkbox may open the order instead of ticking it
  (same symptom as the upstream bug `woocommerce/woocommerce#67906`). The targeted workaround in
  `inc/woocommerce.php` covers the HPOS and legacy screens, blocks only the propagation of the
  checkbox click and keeps the rest of the row clickable. Only remove it after verifying
  an upstream fix.
- **PowerBoost cache** (o2switch) sometimes serves a stale page.
- **CSS/JS browser cache**: the theme enqueues `main.css` / `main.js` with `filemtime()` as
  `?ver` (and no longer `LUZIAPI_VERSION`, frozen at `1.0.0`). This was the cause of invisible style
  changes. When a CSS change "does not show up", check the `?ver` actually served.
- **Interrupted deployment = prod in 500, masked by the cache.** A `make deploy` (mirror of the whole
  theme) cut off in progress (timeout, network, exhausted credits) leaves `src/` **partially** uploaded.
  Since `functions.php` boots `PilotageServiceProvider::boot()`, a missing class causes a
  **fatal on every page loading the theme** — but PowerBoost keeps serving the home in 200,
  hiding the failure. Diagnosis: test an **uncached URL** (`/wp-login.php`, `/mon-compte/`, or the
  home with `?nocache=…`) and run **`make verify-prod`** (`scripts/verify-prod-integrity.sh`) which
  compares the SHA-256 of **all** the theme (the ~288 tracked code files) between the repository and prod
  and **lists precisely the missing/divergent files** (replaces the manual `find src/ | wc -l`).
  Repair: re-`mirror -R` (without `--delete`) of the affected folder, `opcache_reset()`, then re-run
  `make verify-prod`. Observed on 10 September 2026 (deploy interrupted for lack of credits, prod at 27/109
  files `src/Pilotage/`, site in 500 ~24 h). Details in [docs/prod-o2switch.md](docs/prod-o2switch.md).
  Prefer **targeted FTPS and SHA check** (`scripts/deploy-files.sh`) over `make deploy`.
- **`dbDelta` does not change the nullability of an existing column.** Observed on 10 September 2026:
  the `sequence_number` column of `luziapi_receipts`, created long ago as `NOT NULL`, stayed so despite
  a schema switched to `NULL` — the repository inserting NULL then filling it, **every receipt
  record failed** silently. For a nullability (or type) change, add an
  explicit `ALTER TABLE … MODIFY` in the versioned migration (`PilotageSchemaManager`), never rely
  on `dbDelta` alone.

### Production go-live process since 6 September 2026

- Two choices at checkout: **pickup at the LuziApi home in Luzillé by appointment** and
  **free delivery to Luzillé or Bléré by appointment**.
- The postal code 37150 covers other municipalities: delivery must be validated on the triple
  country France + postal code 37150 + normalized city (`Bléré` or `Luzillé`), never on the
  postal code alone.
- Pickup remains available outside these two towns; its location is fixed. Only the day and
  time of the appointment are to be agreed.
- The pickup e-mail reads the shop address from the WooCommerce settings: do not
  duplicate it in the order workflow code.
- After **En cours** (In progress), processing splits into **En cours de livraison** (Out for
  delivery) or **Prête au retrait** (Ready for pickup), then returns to **Terminée** (Completed)
  after the actual handover.
- The validated texts, their triggers and the diagram are recorded in
  `docs/processus-metier-commandes.md`.

---

## 5. What not to redo

Decisions made deliberately — do not undo them without discussing:

- **TranslatePress**: not to be reinstalled. Infinite recursion via the `gettext` hook when
  Contact Form 7 is rendered by `do_shortcode` in Twig → error 500. The multilingual setup goes
  through a dedicated `/en/` page.
- **Jetpack tracking modules** (`stats`, `woocommerce-analytics`, `blaze`, `subscriptions`):
  disabled for GDPR reasons (data sent to Automattic/USA without consent). Do not
  re-enable them without updating the privacy policy.
- **cookieadmin + cookieadmin-pro**: it is a free + extension combo, **not** a duplicate.
- **Authenticated SMTP `mail.luziapi.fr:465`**: does not work (Exim answers 250 OK but Gmail never
  receives). E-mails go out via native `mail()`, signed with DKIM by o2switch.
- **Native Brevo form `[sibwp_form]`**: unusable on o2switch. Replaced by a home-made form
  + REST route (see docs/prod-o2switch.md).
- **Order creation = single entry point**: any manual order goes through the **Vente** (Sale)
  of the management dashboard (formerly "Vente rapide" (Quick sale), renamed). The native WooCommerce
  creation screen (`wc-orders&action=new` and the legacy `post-new.php?post_type=shop_order`) is
  **redirected** to the Vente and its "Ajouter une commande" (Add an order) button hidden
  (`inc/woocommerce.php`); the **editing** and the **refund** of existing orders remain available.
  This is the condition for a future reliable loyalty (a single creation path). The Vente prefills
  the contact details from the customer directory (guests without an account: source = the in-house
  address book, never the list of WordPress users). Do not restore native creation without
  discussing it. Test: `make e2e-vente-local` (see [docs/tests-vente.md](docs/tests-vente.md)).
- **Receipt = "Terminée" (Completed) order (automatic recording).** Validated business rule: the
  "Terminée" status counts as proof of payment. A subscriber (`WooCommerceReceiptSubscriber`) thus
  automatically carries the receipt to the register at the transition to this status, idempotent (missing
  balance only), excluding orders coming from the Vente which already manage their own. The assisted
  reconciliation and manual entry remain available for special cases, but are no longer the normal
  route. Do not go back to a mandatory manual reconciliation without discussing it. Historical
  catch-up: `make backfill-receipts-local` (`LUZIAPI_BACKFILL_DRY=1` to simulate). Tests: `make e2e-receipt-local`
  and `RecordOrderReceiptHandlerTest`. **Drift monitoring** (read-only): `make audit-receipts-local`
  and `make audit-receipts-prod` (`LUZIAPI_AUDIT_YEAR=2026` for a given year) compare, over the period, the
  recorded receipts to the orders and list three anomalies — valid order without a receipt, divergent amount,
  **orphan receipt** (order gone, the 132 € case of 2026). Core `AuditReceiptDriftHandler`
  + `ReceiptDriftAuditor`; tests `AuditReceiptDriftHandlerTest`, `ReceiptDriftAuditorTest`.
- **Loyalty: "offert" (free gift) and "fidélité" (loyalty) are two distinct things** (explicit
  decision, do not merge them). From the Vente: "**offert**" = free commercial gesture (line at 0 €,
  displayed "offert", removed from stock, **without** loyalty impact); "**Fidélité → offrir un pot**"
  (Loyalty → offer a jar) = jar offered under the program (0 €, removed from stock **and** consumes a
  benefit, bounded to the available benefits). Both are excluded from earning jars. Details and line
  metas in [docs/fidelite.md](docs/fidelite.md). Module `src/Loyalty/`, tests `make e2e-loyalty-local`.
  The **thank-you discount** (batch 3) is yet another thing: a free monetary gesture
  (€ or %), carried as a real WooCommerce reduction (never negative fees),
  applicable in the Vente or afterwards on an order (with automatic receipt correction
  if already collected). Tests `make e2e-discount-local`.
- **PayPal = payment method label only, not a customer gateway.** Explicit decision of
  the user: he does **not** want PayPal as a payment method at checkout on the site. "PayPal"
  is only a **label** for payment in the Vente / the receipts (to trace a payment received
  via PayPal). Do not install or enable a PayPal payment gateway.
- **Excluding an order from loyalty = checkbox on the order sheet, with recalculation.** The meta
  `_luziapi_loyalty_excluded` is set by the "Exclure de la fidélité" (Exclude from loyalty) checkbox
  (workflow metabox); the recalculation is triggered **only when the checkbox changes** (action
  `luziapi_loyalty_exclusion_changed` emitted by the save handler), **not** at every edit — otherwise
  correcting an address would recalculate on the current product config and could remove legitimate
  jars. Tests `make e2e-exclusion-local` / `e2e-exclusion-prod`.

---

## 6. Secrets

No credential must be versioned. The deployment accesses and the API keys live in
`.env.local` (ignored by git); `.env` contains only default values without secrets.
