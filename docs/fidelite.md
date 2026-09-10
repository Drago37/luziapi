# Programme de fidélité LuziApi

> Suivi de l'implémentation de l'issue #4. Ce document décrit les **lots 1 et 2**
> (acquisition des pots + utilisation d'un avantage), en place, et les lots suivants
> restant à faire.

## Règle métier

« **10 pots achetés, le 11e offert.** » Un pot compte dès que la commande qui le
contient passe **« Terminée »** (= encaissée, cohérent avec l'auto-encaissement
de la recette). Chaque tranche de 10 pots nets ouvre **un avantage** (un pot
offert). Exemple : 23 pots = 2 avantages acquis + 3/10 sur la tranche en cours.

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
  consommation / restitution d'avantage, agrégation de la requête, disponibilités,
  et garde-fou de la Vente (refus d'offrir plus d'avantages que disponibles).
- **Intégration bout en bout** (`tools/e2e-loyalty-on-complete.php`,
  `make e2e-loyalty-local`) : vraie commande WooCommerce, vrai journal en base ;
  vérifie compteur (offerts exclus), crédit, consommation d'avantage, **décompte
  du stock des pots offerts**, idempotence, contre-passation/restitution à zéro,
  puis nettoie toutes les données créées.

## Reste à faire (lots suivants de l'issue #4)

- **Lot 3** — remise « remerciement » manuelle depuis la fiche client.
- **Lot 4** — affichage côté client (compte / e-mails), migration de l'ancien
  « 1 €/pot » et communication.
