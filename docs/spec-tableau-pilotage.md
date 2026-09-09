# Spécification — Tableau de pilotage des commandes

> État : lot fonctionnel réalisé et validé en local le 9 septembre 2026, non déployé. Le tableau
> de pilotage respecte l'architecture décrite dans `CONTRIBUTING.md`.

## 1. Objectif

Créer le premier écran du menu WooCommerce afin de donner à LuziApi une vue lisible de son
activité : commandes, clients, produits, sources commerciales et recettes réellement encaissées.
L'outil doit également servir de carnet de commandes et préparer les montants nécessaires aux
obligations déclaratives, sans se présenter comme un logiciel comptable certifié.

## 2. Principes de données

- HPOS reste la source de vérité des commandes WooCommerce.
- Le total des commandes est distinct des recettes effectivement encaissées.
- Un statut WooCommerce ne constitue jamais, à lui seul, une preuve d'encaissement.
- Les futurs encaissements forment un registre chronologique séparé, traçable et corrigé par des
  écritures inverses plutôt que par des suppressions silencieuses.
- L'historique client est une projection des commandes, regroupées prudemment par e-mail ou par
  téléphone normalisé. Il ne crée ni compte WordPress ni consentement marketing.
- Toute information chiffrée indique clairement sa période et sa définition.

## 3. Navigation cible

Le premier sous-menu de WooCommerce est **Tableau de bord**. Il donne accès aux vues suivantes :

1. Vue d'ensemble ;
2. Recettes ;
3. Déclaration fiscale ;
4. Commandes ;
5. Clients ;
6. Produits ;
7. Stocks et lots ;
8. Vente rapide ;
9. Journal d'activité.

L'écran natif WooCommerce et la liste native des clients restent disponibles. Le Répertoire
clients LuziApi actuel n'est retiré qu'après validation de sa nouvelle vue équivalente.

## 4. Vue d'ensemble annuelle

La période par défaut est l'année civile courante. Les indicateurs prévus sont :

- recettes encaissées ;
- montant des commandes validées ;
- montant restant à encaisser ;
- remboursements ;
- nombre de commandes ;
- nombre de pots ;
- panier moyen ;
- nouveaux clients et clients récurrents.

Les graphiques présentent l'évolution mensuelle, les produits, les sources de commande, les modes
de remise et les moyens de règlement. Une zone « À traiter » signale les commandes en attente de
règlement, en préparation, à remettre ou incohérentes.

La vue distingue désormais le montant des **commandes validées** des recettes du registre. La zone
« À faire » classe les règlements manquants, préparations, remises et informations à compléter ;
une même commande peut légitimement apparaître dans plusieurs catégories.

## 5. Registre des recettes

Chaque écriture contient au minimum :

- un numéro chronologique ;
- la commande et la pièce justificative associées ;
- la date réelle de l'encaissement ;
- le montant en centimes ;
- la devise ;
- le moyen de règlement ;
- le type : encaissement, remboursement ou correction ;
- l'auteur et la date de saisie.

Les exports annuels sont disponibles en CSV et dans une vue imprimable paginée. Ils regroupent les
recettes par mois et par moyen de règlement.

Le registre est implémenté dans une table WordPress dédiée `luziapi_receipts` (avec le préfixe de
la base). Une écriture enregistrée n'est ni modifiée ni supprimée depuis l'interface : une erreur
est compensée par une contre-écriture reliée à l'originale. L'installation et les migrations sont
idempotentes et versionnées par une option WordPress.

Un rapprochement assisté compare chaque commande commercialement valide au total déjà enregistré
pour elle. Il ne déduit jamais un paiement du seul statut WooCommerce : LuziApi doit confirmer le
montant, la date réelle et le moyen de règlement. Les écarts négatifs sont signalés comme des
surplus à vérifier et ne génèrent aucune correction automatique.

## 6. Déclaration fiscale micro-BA

LuziApi relève du régime micro-BA. L'écran **Déclaration fiscale** est séparé du tableau commercial
afin de ne pas confondre commandes et encaissements. Il présente :

- les recettes brutes réellement encaissées à reporter, sans déduire soi-même l'abattement ;
- le détail des trois années entrant dans la moyenne, ou des seules années disponibles au début
  de l'activité ;
- la moyenne retenue ;
- l'abattement indicatif de 87 %, avec un minimum de 305 € ;
- la base imposable indicative restante de 13 % ;
- les remboursements et corrections pris en compte ;
- un export annuel détaillé et une mention rappelant que l'outil ne remplace pas la déclaration
  officielle.

Les références à une case précise du formulaire fiscal ne sont jamais codées en dur : elles
doivent être vérifiées pour le millésime concerné. L'écran utilise le registre des recettes,
permet de renseigner l'année de début d'activité et garde le montant commercial des commandes à
part pour faciliter les contrôles. Les calculs restent présentés comme une aide indicative.

## 7. Carnet de commandes

La vue Commandes permet de filtrer par période, statut, source, client, produit, remise,
encaissement et moyen de règlement. Elle affiche la chronologie de chaque commande, ses notes
privées et publiques clairement distinguées, les e-mails envoyés, les mouvements de stock et le
rendez-vous éventuel.

## 8. Historique client

Une fiche client rassemble :

- coordonnées connues ;
- commandes passées ;
- total commandé et total encaissé ;
- dernière commande ;
- produits habituels ;
- sources commerciales ;
- chronologie et notes internes.

Une adresse e-mail est prioritaire pour l'identification. Un téléphone normalisé est utilisé en
l'absence d'e-mail ou pour rapprocher un historique non ambigu. Les homonymes ou coordonnées
partagées ne sont jamais fusionnés automatiquement.

Cette projection sera réutilisable par le futur suivi public sans compte, qui n'exposera que les
commandes et notes publiques après une vérification adaptée.

La nouvelle fiche est implémentée : elle distingue total commandé et total encaissé, affiche les
produits habituels, les sources, l'historique complet et une chronologie où chaque note est
explicitement marquée « privée » ou « visible par le client ». L'ancien Répertoire clients reste
volontairement présent jusqu'à validation explicite de son remplacement.

## 9. Vente rapide

La vente rapide crée en une seule opération la commande HPOS, le mouvement de stock, la source
commerciale et, lorsque la vente est marquée comme payée, l'encaissement.

- produits et quantités obligatoires ;
- nom facultatif ;
- e-mail facultatif ;
- téléphone facultatif ;
- e-mail et téléphone peuvent être tous les deux absents : la vente devient « Client de passage » ;
- aucune fausse adresse n'est générée ;
- une coordonnée renseignée est validée et normalisée ;
- une livraison exige son adresse ;
- l'envoi d'e-mail n'est proposé que lorsqu'une adresse existe ;
- les ventes téléphone ou marché peuvent suivre tout le workflow sans envoyer d'e-mail.

La vente rapide est implémentée. Une vente réglée alimente automatiquement le registre des
recettes ; si cet enregistrement échoue après la création de la commande, l'interface indique
clairement que la commande existe déjà afin d'éviter une double saisie. La remise immédiate, le
retrait et la livraison suivent des règles distinctes, et une livraison reste limitée aux zones
déjà acceptées par la boutique.

Chaque formulaire possède un identifiant unique conservé dans la commande. Une nouvelle soumission
du même formulaire retrouve la commande existante au lieu d'en créer une seconde ; le bouton est
également verrouillé dès la première soumission côté navigateur.

## 10. Stocks, lots et récoltes

L'écran **Stocks et lots** ajoute un journal de stock spécifique à LuziApi sans remplacer le stock
WooCommerce, qui reste la référence empêchant la vente de pots indisponibles.

- une récolte correspond à un seul lot, car elle est entièrement mise en pots en une fois ;
- elle porte un numéro de lot unique, la date de récolte, la date de mise en pots, le rucher ou
  l'origine, la variété, le produit et la quantité mise en pots ; il n'existe pas de numéro de
  récolte distinct ;
- dans le fonctionnement actuel de LuziApi, une récolte produit uniquement des pots de 1 kg : un
  lot n'a donc pas à être réparti entre plusieurs poids ou conditionnements ;
- créer un lot ajoute simultanément les pots au stock WooCommerce et une écriture initiale au
  journal ;
- lors de la mise en service, le mode « Stock existant » rattache une quantité déjà présente dans
  WooCommerce à sa récolte sans augmenter le stock une seconde fois ; cette quantité ne peut pas
  dépasser l'écart non affecté du produit ;
- les sorties manuelles couvrent pot cassé, dégustation, cadeau, consommation personnelle et
  correction d'inventaire ;
- une sortie est affectée à un lot lorsqu'un lot disponible existe pour le produit ;
- les commandes WooCommerce consomment d'abord le stock historique non affecté, puis
  automatiquement les lots les plus anciens disponibles ;
- depuis une commande, chaque ligne produit peut cibler une récolte précise avant le décompte du
  stock ; sans choix manuel, la règle précédente reste appliquée ;
- une restauration de stock remet les quantités dans les mêmes lots et reste idempotente ;
- la part du stock historique sans lot reste visible comme « écart non affecté » ;
- les tables `luziapi_harvest_lots` et `luziapi_stock_movements` sont créées par la migration
  versionnée du module.

## 11. Journal d'activité

Le **Journal d'activité** conserve les actions réalisées après son activation dans une table
dédiée `luziapi_activity_log` (avec le préfixe WordPress), créée par la migration de schéma en
version 6 et non modifiable depuis l'interface. Il couvre les commandes et leurs statuts ou notes,
les clients enregistrés, les recettes, récoltes, mouvements de stock, choix de lot, réglages,
exports et échecs métier importants.

Chaque entrée indique la date et l'heure, l'utilisateur ou le système WooCommerce, le domaine,
l'objet concerné, un résumé et des détails sobres qui ne recopient pas inutilement les
coordonnées personnelles. La page offre des filtres par période, domaine et recherche, ainsi qu'un
export CSV protégé contre l'injection de formules. Un aperçu des sept derniers jours figure sur la
vue d'ensemble.

Pour les notes de commande, le journal conserve l'ajout et la visibilité « privée » ou « visible
par le client », mais ne duplique pas le texte de la note : son contenu reste dans l'historique
WooCommerce de la commande.

Le journal n'invente aucun historique antérieur à son activation : les anciennes notes de
commande restent consultables dans WooCommerce. Une panne du journal ne doit jamais bloquer
l'opération métier observée.

## 12. Architecture et interface

- code PHP sous `src/`, namespace `LuziApi\\`, autoload PSR-4 Composer ;
- Domain sans dépendance WordPress ou WooCommerce ;
- cas d'utilisation dans Application ;
- adaptateurs HPOS, WordPress, exports et cache dans Infrastructure ;
- contrôleurs d'administration minces dans UserInterface ;
- assemblage explicite dans Bootstrap, sans conteneur de dépendances supplémentaire ;
- vues Twig séparées du PHP ;
- Chart.js versionné localement, sans CDN ni transfert de données externe ;
- JavaScript natif et styles chargés uniquement sur les écrans du module.

## 13. État de réalisation

Réalisé et testé localement :

1. socle PSR-4 et vue annuelle ;
2. nouvelle vue Clients enrichie ;
3. registre immuable des encaissements, contre-écritures, impression et export CSV ;
4. centre de suivi et contrôles de cohérence ;
5. vue Produits avec ventes, chiffre d'affaires commercial et alertes de stock ;
6. vente rapide avec coordonnées facultatives, stock, commande HPOS et encaissement ;
7. vue Déclaration fiscale micro-BA fondée sur le registre ;
8. prévention des doubles ventes rapides et rapprochement assisté des commandes ;
9. lots de récolte et journal des mouvements de stock, reliés au stock WooCommerce ;
10. reprise contrôlée du stock existant et journal général des activités.

Contrôles réalisés sur ce lot :

- 113 tests PHPUnit, 225 assertions ;
- PHPStan sans erreur et PHP-CS-Fixer conforme ;
- syntaxe JavaScript validée et parcours des écrans contrôlé dans un navigateur sans erreur ;
- migration locale du schéma jusqu'à la version 6 ;
- essais réversibles en base du journal (écriture, recherche et nettoyage) ;
- essai réel du rattachement d'un pot déjà compté : lot et mouvement créés sans variation du stock
  WooCommerce, puis données de test supprimées.

Restent hors de ce lot :

- le remplacement de l'ancien Répertoire clients, soumis à validation après comparaison ;
- le futur suivi public des commandes sans compte ;
- les éventuels rendez-vous datés et relances automatisées, qui demanderont une spécification
  dédiée avant tout envoi au client ;
- les catégories et étiquettes clients, dont la liste doit être validée avant développement ;
- l'intégration de PHPUnit à la CI, suivie dans l'issue GitHub n°2 ;
- la stratégie de tests complète, suivie dans l'issue GitHub n°3.

Chaque évolution doit préserver les fonctions existantes et recevoir des tests unitaires,
d'intégration ou E2E proportionnés à son impact.
