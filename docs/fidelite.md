# Programme de fidélité LuziApi

> Suivi de l'implémentation de l'issue #4 : acquisition des pots, utilisation d'un
> avantage, remise remerciement, affichage client et page de pilotage — tout en
> place. Les évolutions (seuil 15, cumul sans expiration, réconciliation…) sont
> résumées en fin de document.

## Règle métier

« **15 pots achetés, le 16e offert.** » Un pot compte dès que la commande qui le
contient passe **« Terminée »** (= encaissée, cohérent avec l'auto-encaissement
de la recette). Chaque tranche de 15 pots nets ouvre **un avantage** (un pot
offert). Exemple : 33 pots = 2 avantages acquis + 3/15 sur la tranche en cours.
**Les pots n'expirent pas** : ils se cumulent sans limite de validité, et les
avantages acquis (utilisés ou non) restent définitifs. Règle unique : 15 = 1.

Un client est identifié comme dans le tableau de pilotage : **e-mail prioritaire,
sinon téléphone normalisé**. Un même client peut avoir plusieurs e-mails /
téléphones ; la fiche agrège tous ses identifiants.

## Ce que fait le lot 1 (acquisition)

- **Crédit automatique** au passage « Terminée » : les pots éligibles de la
  commande sont écrits dans un journal. Les **ventes rapides comptent** comme les
  autres commandes.
- **Contre-passation automatique** si la commande **quitte** l'état « Terminée »
  (annulée, remboursée, remise en attente) : le crédit d'origine est exactement
  annulé, le total net revient à zéro.
- **Fiche client** (Pilotage → onglet Clients) : carte « Fidélité » affichant les
  pots nets, les avantages disponibles, la progression vers le prochain pot
  offert, et le détail des mouvements.

## Ce que fait le lot 2 (utiliser un avantage)

Depuis la **Vente**, deux gestes **distincts** — à ne pas confondre :

- **Offrir un produit** (geste commercial) : la case « dont offert » d'une ligne
  produit met la quantité correspondante à **0 €**, l'affiche « offert » sur la
  commande, la **sort du stock**, mais **sans lien avec la fidélité**.
- **Fidélité → offrir un pot au titre de la fidélité** : l'encart « Fidélité »
  (visible seulement si le client a des avantages disponibles) ajoute un pot
  offert (0 €, « offert — fidélité », **sorti du stock**) et **consomme un
  avantage**. Impossible d'en offrir plus que le client n'en a : le nombre
  d'avantages disponibles est calculé et borné à la volée.

Techniquement, les deux produisent une **ligne offerte** exclue du gain de pots
(on ne gagne pas un pot en recevant un pot gratuit) ; seule la ligne « fidélité »
porte en plus le marqueur qui décompte l'avantage. La consommation est écrite au
passage « Terminée » (comme le crédit) et contre-passée si la commande en sort :
l'avantage est alors rendu au client.

Le programme **n'affiche pas encore** l'état de fidélité côté client (compte /
e-mails) : c'est l'objet du lot 4.

## Ce que fait le lot 3 (remise remerciement)

Geste commercial **monétaire libre** (€ ou %), **sans lien avec les avantages
fidélité** — à ne pas confondre avec le pot offert (lot 2). Portée comme une
**vraie réduction** WooCommerce (`discount_total` natif, jamais des frais
négatifs), donc affichée correctement sur la commande et les e-mails. Toujours
**bornée au total remisable** et arrondie au pas de la devise. Deux chemins :

- **À la création, dans la Vente** : champ « remise remerciement » ; la commande
  et la recette intègrent d'emblée le total remisé (rien à corriger).
- **A posteriori, depuis la fiche client** : bouton sur une commande déjà passée.
  Si elle était **déjà encaissée**, la recette est corrigée automatiquement par
  une contre-écriture `Refund` du montant réellement remisé, pour que la compta
  reste juste. Le geste est tracé dans le **journal d'activité**.

Composants : VO `Domain/Sales/ThankYouDiscount` (calcul + plafond), commande
`Application/Command/ApplyThankYouDiscount` (chemin a posteriori + correction de
recette + traçabilité), helper `Infrastructure/WooCommerce/WooCommerceThankYouDiscount`
(vraie réduction, partagé Vente/fiche) et `WooCommerceOrderDiscountWriter`.

## Produits éligibles

Un produit compte comme « pot » seulement si la case **« Pot admissible à la
fidélité »** est cochée dans sa fiche (métabox « Options LuziApi »,
méta `_luziapi_pot_admissible = yes`). À cocher pour les pots de miel, à laisser
décoché pour les coffrets, frais, cartes cadeaux, etc.

> **Mise en route** : penser à cocher la case sur les pots déjà en catalogue (ou
> à faire un backfill de la méta) — sans quoi les commandes ne créditent aucun pot.

## Architecture

Contexte hexagonal `src/Loyalty/` (même patron que Pilotage et OrderTracking) :

- **Domain** — `LoyaltyEntry` / `NewLoyaltyEntry` (écritures immuables),
  `LoyaltyEntryType` (enum), `LoyaltyLedger` (port du journal), `LoyaltyProgress`
  (calcul « 10 → 1 »), `LoyaltyIdentity` (clé d'identité, identique au projecteur
  Pilotage).
- **Application** — commandes idempotentes : `RecordCompletedOrder` /
  `ReverseOrderCredit` (crédit des pots, clés `credit:{id}` / `reverse:{id}`),
  `RecordRewardConsumption` / `ReverseRewardConsumption` (avantage utilisé / rendu,
  clés `reward:{id}` / `reward-reversal:{id}`) ; requête `GetCustomerLoyalty`
  (agrégation pour la fiche + avantages disponibles pour la Vente) ; port `Clock`.
- **Infrastructure** — `LoyaltySchemaManager` (table
  `{prefix}luziapi_loyalty_ledger`), `WordPressLoyaltyLedger` (journal
  append-only, `INSERT IGNORE` sur `idempotency_key`), `WordPressClock`, et côté
  WooCommerce : `WooCommerceEligiblePotCounter` (compte les pots admissibles hors
  lignes offertes, et à part les pots offerts fidélité, ajusté des remboursements),
  `WooCommerceOrderIdentityResolver`, `WooCommerceLoyaltyEarningSubscriber` (hooks
  `woocommerce_order_status_completed` et `woocommerce_order_status_changed`).

### Marqueurs de ligne (métas d'article de commande)

- `_luziapi_offert = yes` : ligne offerte (0 €), qu'elle soit un geste commercial
  ou un pot offert fidélité — **exclue du gain de pots**.
- `_luziapi_loyalty_reward = yes` : ligne offerte **au titre de la fidélité**, qui
  **consomme un avantage** (porte aussi `_luziapi_offert`).
- Méta visible `Offert` (`Oui` ou `Fidélité`) : affiche « offert » sur la commande
  et les e-mails.

Le stock des lignes offertes est décompté nativement par WooCommerce
(`wc_reduce_stock_levels`), comme toute ligne, indépendamment du prix nul.
- **Bootstrap** — `LoyaltyServiceProvider::boot()` (démarré **avant** le Pilotage
  dans `functions.php`, car la fiche client lit son handler de lecture).

Le journal est **append-only** : une erreur ne se corrige jamais par modification,
mais par une écriture compensatrice. L'idempotence garantit qu'un même événement
(crédit/annulation d'une commande) n'est jamais compté deux fois, même si un hook
se déclenche plusieurs fois.

### Pourquoi dupliquer la logique d'identité plutôt que la partager

`LoyaltyIdentity` recalcule la clé exactement comme
`CustomerHistoryProjector::identityId()` (Pilotage). Elle réutilise le VO
`NormalizedPhone` (source unique de la normalisation téléphone) mais garde son
propre hachage, **verrouillé par un test golden** (`LoyaltyIdentityTest`). Si l'un
des deux algorithmes dérivait, la fiche afficherait zéro pot : le test golden fige
les valeurs pour l'empêcher.

## Tests

- **Unitaires / intégration légère** (`tests/Loyalty/`, `tests/Pilotage/`,
  `make test`) : progression, identité (golden), crédit / contre-passation,
  consommation / restitution d'avantage, agrégation, disponibilités, garde-fou de
  la Vente, calcul et plafond de la remise remerciement, et correction de recette
  a posteriori.
- **Intégration bout en bout** :
  - `make e2e-loyalty-local` : compteur (offerts exclus), crédit, consommation
    d'avantage, **décompte du stock des pots offerts**, idempotence,
    contre-passation/restitution à zéro.
  - `make e2e-discount-local` : remise portée en `discount_total` natif (pas de
    frais négatifs), à la création et a posteriori, plafond au total, précision
    devise.
  - `make e2e-vente-loyalty-local` : le chemin RÉEL de la Vente
    (`WooCommerceQuickSaleOrderWriter::create`) avec pot offert (geste), pot offert
    fidélité et remise — vérifie les lignes marquées à 0 €, la remise en
    `discount_total`, le décompte du stock, puis le crédit des pots et la
    consommation de l'avantage écrits par le subscriber du thème à « Terminée ».
  - `make e2e-loyalty-client-local` : les surfaces client — le bloc fidélité est
    réellement **rendu dans l'e-mail « Terminée »** avec le bon compteur, le
    handler de suivi branché **agrège plusieurs commandes** d'un client, et la
    **remise a posteriori corrige la recette** au registre (vrai
    `WordPressReceiptRepository`).
  Tous nettoient les données créées.
- **Bout en bout sur la PRODUCTION** : `make e2e-loyalty-prod` (script
  `scripts/e2e-loyalty-prod.sh` + runner à jeton `tools/e2e-loyalty-prod.php`).
  Dépose un script à jeton à usage unique à la racine du thème, l'appelle en
  HTTPS, affiche le résumé JSON, puis le supprime (vérifie le 404). Il crée un
  **produit masqué** et **une commande de test isolés** (e-mail aléatoire) puis
  les supprime : le statut « Terminée » est posé via `set_status` — donc **aucun
  hook, aucun e-mail, aucune recette, aucun mouvement de stock de complétion** —
  et une ceinture `pre_wp_mail` bloque tout envoi. Couvre le cycle complet sur les
  vraies classes et la vraie base : résolution d'identité, compteur (offerts
  exclus, pot offert fidélité), réconciliation à « Terminée », lecture fiche
  client **et** suivi sans compte, **remboursement partiel**
  (net = 2), annulation (retour à zéro, avantage rendu), et absence de ligne de
  journal résiduelle. À rejouer après tout déploiement touchant `src/Loyalty/`.
  Le runner vit sous `tools/` (exclu du déploiement) et est **uploadé au runtime**
  par le script : rien de tout cela n'atterrit en prod de façon permanente.

## Ce que fait le lot 4 (affichage côté client — en cours)

Le client n'a **pas de compte** (site guest-only). Son état fidélité s'affiche donc
sur la **page de suivi de commande**, une fois identifié (n° + e-mail, ou lien
magique « toutes mes commandes ») : un bloc « Fidélité » **toujours présent**
(rappel du programme même à zéro pot), avec pots cumulés, pot(s) offert(s) à
réclamer et progression vers le suivant. L'identité fidélité est déduite
**côté serveur** des commandes accessibles à la session (jamais d'e-mail exposé au
template), via `GetLoyaltyForOrders` (`OrderContactKeys` + `GetCustomerLoyalty`).

**Le « 1 €/pot » n'est PAS touché** : c'est la remise de volume au panier
(`inc/shop.php`, −1 € par pot dès 2 pots au checkout du site), sans rapport avec la
fidélité. Aucune migration : elle reste telle quelle.

**Rappel fidélité dans l'e-mail « Terminée »** : l'e-mail de commande terminée
(`Luziapi_Email_Customer_Completed`) affiche le compteur réel — pots cumulés,
pot(s) offert(s) à réclamer, progression. Uniquement cet e-mail : c'est le seul
moment où le compteur est à jour (les pots sont crédités à « Terminée »). Pour
garantir la fraîcheur, le crédit fidélité passe désormais en **priorité 5** sur
`woocommerce_order_status_completed` (avant l'envoi des e-mails, priorité 10). La
donnée vient de `luziapi_email_loyalty_summary()` (`inc/customer-emails.php`),
rendue dans les deux variantes (HTML + texte).

## Évolutions (septembre 2026)

- **Seuil porté à 15** (le 16e offert) : `LoyaltyProgress::POTS_PER_REWARD`. Les
  textes client suivent la variable ; les libellés en dur ont été mis à jour.
- **Pas d'expiration (cumul illimité)** : les pots n'expirent jamais et se cumulent
  (15 = 1, 30 = 2…). L'ancienne fenêtre glissante de 2 ans a été **retirée**
  (constante `POT_LIFETIME_YEARS`, paramètre `potsSince` du journal et horloge de
  lecture supprimés). Le backfill rattrape donc **tout** l'historique « Terminée ».
- **Moteur en réconciliation** : le couple crédit / contre-passation est remplacé
  par une réconciliation (`ReconcileOrderLoyalty`) qui porte le journal de chaque
  commande à son état cible et n'écrit que l'écart. Couvre uniformément le
  **remboursement partiel** (hook `woocommerce_order_refunded`), le total,
  l'annulation et la re-complétion. Convergent et idempotent. `orderTotals()` sur
  le journal en est la base.
- **Ajustement manuel** des pots depuis la fiche client (`AdjustLoyaltyPots`,
  écriture `ManualAdjustment`).
- **Passif sur le dashboard** : l'encart de synthèse affiche « Avantages dus
  (passif) » = total des avantages disponibles non réclamés, tous clients (les
  pots offerts que la boutique devra honorer). Port `LoyaltyRewardsReader` +
  adaptateur `LoyaltyModuleRewardsReader`.
- **Liens d'identité** (pots qui n'expirent pas = il faut regrouper un client connu
  sous plusieurs identités) : port `LoyaltyIdentityLinks` (table `…_identity_links`,
  schéma v2) qui rattache les clés d'un même client à une **canonique**. **Auto-liaison
  PRUDENTE** (`autoLink`) par le subscriber de crédit **et** le backfill, uniquement sur
  une commande **Terminée non exclue** portant e-mail **et** téléphone, et seulement si
  le téléphone **n'est pas déjà rattaché** : ainsi plusieurs téléphones s'attachent à un
  même e-mail (changement de numéro) et une commande téléphone-seul se rejoint, mais un
  **téléphone de foyer / partagé n'absorbe jamais un 2ᵉ e-mail** (cas ambigu). Ce cas
  ambigu et les « 2 e-mails sans téléphone commun » relèvent de la **fusion manuelle**
  depuis la fiche (`MergeLoyaltyIdentities`, admin-post `luziapi_merge_loyalty_customers`),
  réversible par **défusion** (`unlink`, bouton « Détacher ce client », admin-post
  `luziapi_unlink_loyalty_customer`). Les lectures **mono-client** (`handle` /
  `availableRewards`) étendent les clés au groupe ; la **somme multi-clients** (passif)
  ne l'étend PAS (anti double-comptage). Tests : `LoyaltyIdentityLinksTest` + e2e
  `make e2e-identity-links-local` (auto prudent, refus du 2ᵉ e-mail partagé, fusion, défusion)
  et `make e2e-merge-admin-local` (vrai chemin admin fusion/défusion).
  _Risque résiduel assumé (axe e-mail)_ : la prudence ne porte que sur le téléphone (e-mail =
  ancre stable pour le changement de numéro). Un **e-mail partagé / placeholder** (ex. l'adresse
  de la boutique saisie en Vente pour des passages, ou une adresse de couple) avec deux
  téléphones différents regrouperait deux personnes. C'est **largement pré-existant** — deux
  personnes sous le même e-mail ont déjà leurs pots poolés par la clé de crédit (e-mail
  prioritaire), indépendamment des liens ; l'auto-lien n'y ajoute que d'éventuelles commandes
  téléphone-seul. Parade : la **défusion** corrige a posteriori ; si un e-mail placeholder connu
  pose problème, l'exclure de l'auto-lien (denylist) côté subscriber/backfill est une option.
- **Audit de dérive** (lecture seule, comme les recettes) : `make audit-loyalty-local`
  / `make audit-loyalty-prod` (`LUZIAPI_AUDIT_YEAR=2026` pour une année) listent deux
  anomalies — **trou de crédit** (commande admissible « Terminée » jamais créditée →
  produit non coché « admissible » ou backfill à lancer) et **crédit orphelin**
  (commande disparue encore positive au journal). Cœur `AuditLoyaltyDriftHandler` +
  `LoyaltyDriftReport` ; ports `EligiblePotReader` / `LoyaltyLedgerReader` ; tests
  `AuditLoyaltyDriftHandlerTest` et e2e `make e2e-audit-loyalty-local`. À lancer
  **avant** un backfill prod pour voir les trous.
- **Surfaces client** : rappel du programme dans l'**e-mail de confirmation**
  (on-hold / processing, `luziapi_email_loyalty_reminder()`), bloc fidélité sur la
  **boutique / fiche produit / panier** (`luziapi_offer_html`), sur l'**accueil**
  (section « Nos miels ») et sur la **page de suivi** (visible avant connexion).
- **Ajouter un pot offert à une commande existante** : bloc « Ajouter un pot
  offert » de la fiche commande (`inc/order-workflow.php`), geste commercial **ou**
  fidélité. Seules des **lignes à 0 €** sont ajoutées (via
  `OfferedOrderItem::addTo`) : le **montant de la commande ne change pas** — une
  commande « Terminée » ne peut pas être modifiée en montant, la recette déjà
  encaissée reste intacte. Le stock du seul nouvel item est décompté (marqué
  `_reduced_stock`) ; la fidélité recalcule via `luziapi_loyalty_order_lines_changed`
  (avantage consommé, borné au disponible). Tests `make e2e-offered-pot-local` /
  `e2e-offered-pot-prod`.

## Lot 4 — page publique, légal et backfill rétroactif (PR #35)

- **Page publique « Programme de fidélité »** (`templates/page-programme-de-fidelite.twig`, slug
  `programme-de-fidelite`, routée par `page.php`) : intro conviviale + **règles complètes**
  (13 sections), imprimable en PDF durable. Les chiffres (seuil, validité) sont lus du domaine via
  `luziapi_loyalty_program_numbers()` (`inc/loyalty-legal.php`), jamais codés en dur. Lien au footer
  (FR + EN). **Le règlement versionné** vit dans cette page (source unique) + un PDF immuable
  `assets/docs/LuziApi-Reglement-Fidelite-<version>.pdf` (constante `LUZIAPI_LOYALTY_REGLEMENT_*`).
- **CGV** : nouvelle version `2026-09-14-v4` + section « 14. Programme de fidélité » renvoyant au
  règlement ; `luziapi_cgv_pdf_url()` masque le bouton si le PDF n'est pas déposé (plus de 404).
  **Politique de confidentialité** : finalité fidélisation, base légale (intérêt légitime, ≠
  consentement newsletter), conservation.
- **Backfill rétroactif durci** (`tools/backfill-loyalty.php`) : il ignore désormais toute commande
  ayant **déjà la moindre écriture** au journal (`LoyaltyLedger::hasEntryForOrder`), et non plus la
  seule clé `credit:{orderId}` — le moteur live créditant par réconciliation (`reconcile-*`), l'ancien
  test aurait **doublé** les pots d'une commande récente. Ciblable par IDs (isolation / rollout).
  Runner **PROD** à jeton `scripts/backfill-loyalty-prod.sh` (`make backfill-loyalty-prod`) :
  **simulation par défaut**, écrit seulement avec `APPLY=1`. e2e `make e2e-backfill-loyalty-local`
  (dont non-régression du double comptage).

## Reste à faire

- **Déploiement** (checklist PR #35) : créer la page WP de slug `programme-de-fidelite` en prod ;
  vérifier que les pots au catalogue sont cochés « admissibles » ; lancer le backfill en 2 temps
  (simulation puis `APPLY=1`) après déploiement + feu vert.
- **Communication de lancement** (action non-code, à préparer et valider ensemble ; brouillons
  gardés hors dépôt). Rappel : la 1ʳᵉ publication d'un article déclenche l'auto-envoi newsletter.
