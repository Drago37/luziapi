# Tests de la Vente (création de commande unifiée)

## Périmètre

La **Vente** (`admin.php?page=luziapi-pilotage&tab=quick-sale`, ex-« Vente rapide ») est le
**point d'entrée unique** de la création manuelle de commande. Elle préremplit les coordonnées
d'un client existant dans deux sens :

1. depuis la **fiche client** → bouton « Créer une vente pour ce client » (`&customer=<id>`) ;
2. depuis la Vente → un **sélecteur de client** qui remplit nom / e-mail / téléphone / ville.

Les clients sont des **invités sans compte** : la source est le répertoire maison
(`GetCustomerDirectory`), jamais la liste des utilisateurs WordPress.

Les listes déroulantes du tableau de pilotage (dont le sélecteur de client) sont enrichies en
**champs autocomplete** via `selectWoo`/`select2` fournis par WooCommerce ; sans JavaScript, les
`<select>` natifs restent pleinement fonctionnels.

## Suite unitaire

```bash
make test          # toute la suite PHPUnit
```

Couvre notamment `CustomerProfile::primaryEmail()/primaryPhone()` (choix du contact principal,
`tests/Pilotage/CustomerProfileTest.php`) et la création de vente au niveau domaine
(`CreateQuickSaleHandlerTest`).

## Intégration WordPress, WooCommerce et HPOS

```bash
make e2e-vente-local
```

Le scénario utilise le vrai WooCommerce (HPOS compris) et rend réellement le contrôleur de la
Vente. Il crée deux commandes invitées et un produit brouillon, puis contrôle :

- la **garde du point d'entrée unique** : la création native HPOS
  (`admin.php?page=wc-orders&action=new`) et legacy (`post-new.php?post_type=shop_order`) sont
  reconnues comme à rediriger, alors que l'**édition** et la **liste** ne le sont pas ; la cible
  de redirection est bien la Vente ;
- le **répertoire réel** : le client invité apparaît et son contact principal correspond à la
  commande ;
- le **rendu préremplí** : bandeau « Vente pour … », e-mail et téléphone préremplis, client
  présélectionné dans le sélecteur, aucune adresse e-mail placée dans une URL de la page ;
- que les **deux clients** restent proposables dans le sélecteur (l'admin voit tout le carnet) ;
- que **sans client** sélectionné, rien n'est prérempli et aucun bandeau n'apparaît.

Toutes les commandes et le produit de test sont supprimés à la fin, même en cas d'échec, et aucun
e-mail n'est envoyé.

## Hors couverture automatisée (assumé)

- La **redirection HTTP effective** (le `exit`) et le **masquage CSS** du bouton natif en vrai
  navigateur : seule la décision pure (`luziapi_is_native_order_creation_screen()`) et l'URL cible
  sont testées.
- Le **JavaScript** du sélecteur de client (pas de harnais JS dans le thème) : à vérifier à l'œil
  sur la page.
