# Spécification — Tableau de pilotage des commandes

> État : conception validée, réalisation progressive. Le tableau de pilotage doit respecter
> l'architecture décrite dans `CONTRIBUTING.md`.

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
7. Vente rapide.

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

Tant que le registre des encaissements n'est pas disponible, le premier lot affiche uniquement le
montant des **commandes validées** et précise qu'il ne doit pas être utilisé comme recette fiscale.

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
doivent être vérifiées pour le millésime concerné. Tant que le registre des encaissements n'est
pas disponible, l'écran affiche seulement un repère commercial explicitement non fiscal.

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

## 9. Vente rapide

La vente rapide crée en une seule opération la commande HPOS, le mouvement de stock, la source
commerciale et, lorsque le lot Recettes sera disponible, l'encaissement.

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

## 10. Architecture et interface

- code PHP sous `src/`, namespace `LuziApi\\`, autoload PSR-4 Composer ;
- Domain sans dépendance WordPress ou WooCommerce ;
- cas d'utilisation dans Application ;
- adaptateurs HPOS, WordPress, exports et cache dans Infrastructure ;
- contrôleurs d'administration minces dans UserInterface ;
- assemblage explicite dans Bootstrap, sans conteneur de dépendances supplémentaire ;
- vues Twig séparées du PHP ;
- Chart.js versionné localement, sans CDN ni transfert de données externe ;
- JavaScript natif et styles chargés uniquement sur les écrans du module.

## 11. Lots de réalisation

1. socle PSR-4 et vue annuelle en lecture seule ;
2. nouvelle vue Clients, puis remplacement du Répertoire clients ;
3. registre des encaissements, rapprochement initial et exports ;
4. rendez-vous, alertes et contrôles de cohérence ;
5. vente rapide ;
6. suivi public des commandes sans compte.

Chaque lot doit préserver les fonctions existantes et recevoir des tests unitaires, d'intégration
ou E2E proportionnés à son impact.
