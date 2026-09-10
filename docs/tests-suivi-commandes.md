# Tests du suivi de commande sans compte

## Périmètre

La page `/suivi-commande/` propose deux accès sans créer de compte :

1. une commande précise avec son numéro et l’adresse e-mail de facturation ;
2. toutes les commandes d’une adresse après ouverture d’un lien magique reçu par e-mail.

Les deux parcours reposent sur des sessions temporaires et ne doivent jamais exposer une note
privée, une adresse e-mail dans l’URL ou la commande d’un autre client.

## Suite unitaire

Depuis la racine du dépôt :

```bash
vendor/bin/phpunit tests/OrderTracking
```

Elle couvre notamment :

- validation et normalisation du couple numéro/e-mail ;
- libellés, couleurs fonctionnelles et progression de tous les statuts ;
- accès accordé, refusé, expiré ou révoqué ;
- limitation par identifiant et par origine ;
- adresse connue ou inconnue sans différence observable dans la page ;
- durée de quinze minutes du lien et de deux heures de la session ;
- consommation unique du lien magique ;
- restriction de la passerelle aux seuls identifiants autorisés ;
- enregistrement idempotent des changements de statut ;
- format aléatoire URL-safe des jetons et empreintes non réversibles.

La suite complète du projet reste la référence :

```bash
make test
make cs-check
make stan
```

## Intégration WordPress, WooCommerce et HPOS

```bash
make e2e-tracking-local
```

Cette cible crée ou actualise d’abord la page locale avec les fixtures. Le scénario crée ensuite
treize commandes invitées et un produit brouillon, puis exerce les vrais adaptateurs du thème et
les tables WordPress. Ses assertions contrôlent :

- la recherche HPOS de douze commandes partageant la même adresse ;
- leur pagination par dix et l’isolation d’une treizième commande appartenant à une autre adresse ;
- le formulaire direct avec une bonne puis une mauvaise combinaison ;
- l’émission, la consommation unique et la révocation d’un lien magique ;
- le stockage séparé de l’historique public des statuts ;
- l’affichage de la note publique et l’absence stricte de la note privée ;
- le contenu sécurisé de l’e-mail d’accès, intercepté sans envoi ;
- le bouton de suivi dans les e-mails seulement quand la page est publiée ;
- l’absence d’adresse e-mail dans le lien de suivi.

Toutes les commandes, le produit, les jetons, sessions, compteurs et événements de test sont
supprimés dans un bloc de nettoyage, y compris lorsqu’une assertion échoue. Aucun e-mail réel
n’est envoyé.

## Contrôles HTTP et visuels locaux

Avec la stack Docker lancée, vérifier les en-têtes :

```bash
curl -sS -D - -o /dev/null http://localhost:8080/suivi-commande/
```

La réponse attendue est `200 OK` avec au minimum :

```text
Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0
X-Robots-Tag: noindex, nofollow, noarchive
```

Contrôler ensuite dans un navigateur les formats bureau et mobile : formulaire sans débordement,
navigation clavier, focus visible, messages d’erreur, liste multi-commandes, détail, frise des
statuts et déconnexion.

## Recette de production avant ouverture

La recette de production doit rester sans indexation et sans fuite de cache. Après accord
explicite pour déployer et publier la page :

1. tester une ancienne commande et une nouvelle commande invitée ;
2. ouvrir deux sessions avec deux clients distincts et confirmer qu’aucun contenu ne se croise ;
3. essayer deux fois le même lien magique et après son expiration ;
4. vérifier la suppression du paramètre `acces` après ouverture ;
5. ajouter une note publique et une note privée reconnaissables, puis confirmer que seule la
   première apparaît ;
6. valider les en-têtes sans puis avec un cache-buster pour contrôler PowerBoost ;
7. envoyer un e-mail de chaque famille (LuziApi et WooCommerce natif), en HTML et texte brut ;
8. contrôler sur Gmail mobile et ordinateur que le bouton ouvre la bonne commande.

Il n’existe volontairement pas encore de cible E2E de suivi en production : elle devra utiliser
deux identités de test distinctes et garantir le nettoyage avant d’être ajoutée.
