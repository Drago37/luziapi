# Programme de fidélité LuziApi

> Suivi de l'implémentation de l'issue #4. Ce document décrit les **lots 1 à 3**
> (acquisition des pots, utilisation d'un avantage, remise remerciement), en place,
> et les lots suivants restant à faire.

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
  Tous nettoient les données créées.

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

Reste à faire dans le lot 4 :
- **Communication de lancement** (action non-code, à préparer et valider ensemble).
