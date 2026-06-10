# mu-plugins de production (sauvegarde)

Copies de référence des **mu-plugins** installés en prod sur **luziapi.fr** (o2switch),
dans `public_html/wp-content/mu-plugins/`. Ce dossier **n'est pas déployé** (`make deploy`
ne pousse que le thème) : c'est une **sauvegarde versionnée** pour ne pas perdre cette
logique si le serveur a un souci.

> ⚠️ La **source de vérité reste le serveur**. Si tu modifies un de ces fichiers en prod,
> pense à reporter la modification ici (et inversement).

## Rôle de chaque fichier

| Fichier | Rôle |
|---|---|
| `luziapi-newsletter.php` | Formulaire d'inscription newsletter (design maquette) + route REST `POST /wp-json/luziapi/v1/subscribe` → API Brevo (e-mail + SMS, normalisation FR, honeypot). Définit `LUZIAPI_NEWSLETTER`. |
| `luziapi-newsletter-autosend.php` | À la 1re publication d'un article : programme (~10 min) l'envoi d'une **campagne Brevo** à la liste. Case « ne pas envoyer » dans l'éditeur. Remplace l'ancienne campagne RSS. |
| `luziapi-mail-from.php` | Force l'expéditeur des e-mails du site (`From: LuziApi <no-reply@luziapi.fr>`). |
| `luziapi-cf7.php` | Définit `LUZIAPI_CF7` (shortcode du formulaire de contact Contact Form 7). |
| `luziapi-cookieadmin-style.php` | Habillage FR + style du bandeau cookies. |

## Restauration / mise à jour en prod

Le compte FTP est **chrooté sur le dossier du thème** : on ne peut donc pas déposer ces
fichiers dans `mu-plugins/` par FTP directement. La méthode utilisée (voir mémoire projet) :
déposer un petit **script PHP à jeton** dans le dossier du thème, qui écrit le fichier
voulu dans `WPMU_PLUGIN_DIR` (idéalement via un contenu **base64** pour préserver
l'intégrité), puis `opcache_reset()`, puis supprimer le script.

Aucune clé secrète n'est stockée dans ces fichiers (la clé API Brevo est lue à l'exécution
via `get_option('sib_api_key_v3')`).
