# Modèles SMS — newsletter LuziApi (Brevo)

Modèles de SMS prêts à l'emploi pour les campagnes Brevo (**Campagnes → SMS**),
ciblant la liste **« LuziApi Newsletter »** (Brevo ne garde que les contacts ayant
un numéro dans l'attribut `SMS`).

## Règles à connaître (coût + conformité)

- **1 SMS = 160 caractères** (encodage standard GSM-7). Au-delà : 2 crédits, etc. Brevo affiche un compteur.
- Brevo **ajoute automatiquement la mention STOP** (désinscription obligatoire) → ~15-20 caractères consommés : viser **~140 caractères** de contenu pour rester à **1 crédit**.
- **Commencer par « LuziApi : »** — le nom d'expéditeur n'est pas toujours affiché chez le destinataire.
- **Éviter les emojis et les accents circonflexes/trémas** (`ê â î ô û ë ï ü`) : ils font basculer en encodage **Unicode → 70 caractères par SMS** au lieu de 160. Les accents **`é è à ù ç` passent sans surcoût**.
- **Horaires (reco CNIL)** : pas d'envoi le soir, le dimanche, ni les jours fériés.
- **Expéditeur** : nom alphanumérique ≤ 11 caractères → `LuziApi`.

## Modèles (≈ 1 SMS chacun)

**Nouvelle récolte / disponibilité**

> LuziApi : le miel de printemps est arrivé ! Disponible au rucher à Luzillé ou sur luziapi.fr. Au plaisir de vous régaler.

**Présence sur un marché**

> LuziApi : on vous attend samedi au marché de Bléré, de 8h à 13h. Venez déguster et repartir avec vos pots de miel !

**Stock limité**

> LuziApi : derniers pots de miel d'acacia disponibles ! Pour réserver, appelez le 06 32 85 34 93. Merci à vous.

**Livraison locale offerte**

> LuziApi : livraison gratuite à Luzillé et Bléré cette semaine. Commandez sur luziapi.fr ou au 06 32 85 34 93.

**Message de saison**

> LuziApi : belle année à vous ! Les abeilles se reposent, les nouveaux miels arrivent au printemps. Merci de votre soutien.

## Envoi (rappel)

**Campagnes → SMS** → expéditeur `LuziApi` → cible la liste « LuziApi Newsletter »
→ coller le texte → vérifier le compteur (1 crédit) → envoyer (en respectant les horaires).
