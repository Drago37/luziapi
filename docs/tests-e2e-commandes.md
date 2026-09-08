# Test de bout en bout du process de commande

Ce test vérifie **le parcours réel d'une commande** (statuts, e-mails transactionnels,
échéance de règlement, annulation, stock) de bout en bout, sur un vrai WooCommerce.
Il complète les tests unitaires PHPUnit (`tests/`), qui ne couvrent que la logique pure.

> **À rejouer à chaque changement de process de commande** : workflow des statuts
> (`inc/order-workflow.php`), e-mails (`inc/class-luziapi-order-status-email.php`),
> échéance virement/WERO (`inc/payment-deadline.php`).

Moteur unique : [`www/wp-content/themes/luziapi/tools/e2e-orders.php`](../www/wp-content/themes/luziapi/tools/e2e-orders.php).

## Garanties

- Chaque commande et le produit de test portent la méta `_luziapi_e2e_test`.
- **Nettoyage systématique** en fin de run (bloc `finally`), même en cas d'erreur ;
  un **mode « cleanup seul »** purge tout résidu d'un run précédent.
- **Aucune donnée personnelle dans le dépôt** : l'identité de test est fournie au
  lancement (fichier local ignoré par git, ou corps de la requête en prod).
- Les délais de 5 / 10 jours ouvrés ne sont **pas attendus** : le test vérifie que les
  actions sont bien **planifiées aux bonnes dates**, puis **invoque directement** les
  callbacks de rappel et d'expiration.

## Scénarios

| Clé                | Scénario                                                       | Ce qui est vérifié                                                                                                                                         |
| ------------------ | -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `delivery`         | Commande **livraison** (`free_shipping`), paiement à la remise | mode « delivery » ; e-mails confirmée → en cours de livraison → terminée ; **« prête au retrait » bloqué** ; PDF CGV joint au 1er e-mail                   |
| `pickup`           | Commande **retrait** (`local_pickup`)                          | mode « pickup » ; e-mail « prête au retrait » ; **« en cours de livraison » bloqué**                                                                       |
| `cancel`           | **Annulation** pour non-paiement (virement/WERO `bacs`)        | e-mail « en attente » ; 2 actions planifiées (rappel + expiration) ; rappel envoyé ; expiration → commande annulée + **stock remis** + e-mail d'annulation |
| `paid_on_time`     | Virement **payé à temps**                                      | échéance planifiée à la mise en attente, puis **déprogrammée** au paiement                                                                                 |
| `cancel_no_reason` | **Annulation manuelle sans motif**                             | e-mail d'annulation **non envoyé** + note « motif obligatoire absent »                                                                                     |

## Identité et options (payload JSON)

```json
{
  "identity": {
    "email": "prenom.nom@example.com",
    "first_name": "Prénom",
    "last_name": "Nom",
    "phone": "06 00 00 00 00",
    "address_1": "1 rue Exemple",
    "city": "Luzillé",
    "postcode": "37150",
    "country": "FR"
  },
  "options": {
    "send_emails": false,
    "quantity": 2,
    "cleanup_only": false,
    "scenarios": [
      "delivery",
      "pickup",
      "cancel",
      "paid_on_time",
      "cancel_no_reason"
    ]
  }
}
```

- `send_emails` : `false` = _dry-run_ (e-mails journalisés mais **non expédiés**) ;
  `true` = e-mails **réellement envoyés** à l'adresse `identity.email`.
- Pour le scénario `delivery`, `city` doit être **Luzillé** ou **Bléré** (37150) pour
  refléter la règle réelle de livraison locale.

## Lancer en local (Docker + WP-CLI)

1. Créer le fichier d'identité (ignoré par git) :
   `www/wp-content/themes/luziapi/tools/.e2e-identity.json` avec le JSON ci-dessus.
2. Démarrer la stack si besoin : `make up` puis `make install` (au 1er lancement).
3. Jouer le test :

   ```bash
   make e2e-local
   ```

   > En local, l'envoi d'e-mails n'aboutit nulle part (pas de SMTP) : garder
   > `send_emails: false`. Le local sert à valider **la logique et le nettoyage**.

4. Purger d'éventuels résidus : `make e2e-clean`.

## Lancer en prod (FTPS + jeton HTTPS)

Le plus simple — deux cibles qui déposent le script à jeton à la racine du thème,
l'exécutent, affichent le résumé, puis le suppriment automatiquement :

```bash
make e2e-prod        # dry-run : aucun e-mail expédié, auto-nettoyé
make e2e-prod-send   # e-mails RÉELS vers l'adresse de tools/.e2e-identity.json
```

Elles s'appuient sur [`scripts/e2e-prod.sh`](../scripts/e2e-prod.sh), qui lit les
identifiants FTPS dans `.env.local` et l'identité dans `tools/.e2e-identity.json`.
Compter **~12 e-mails** par run complet avec `--send`.

Le parcours vérifie aussi, sans expédier de messages supplémentaires, que les quatre e-mails
client WooCommerce conservés (paiement échoué, remboursement, note et détails/demande de paiement)
rendent bien le gabarit LuziApi. Il contrôle également qu'un e-mail client déclenché laisse une
trace privée dans la commande. Un retour positif du transport signifie que WordPress a accepté
l'envoi ; il ne constitue pas un accusé de réception de la boîte destinataire.

Sous le capot (utile si l'accès o2switch change), l'accès prod se fait sans SSH par
script à jeton (voir [prod-o2switch.md](prod-o2switch.md) et [AGENTS.md](../AGENTS.md)
§ 4). Le compte FTP est chrooté sur le thème, sans dossier `tools/`, et o2switch filtre
les POST vers un PHP du thème (404). Le script est donc **déposé à la racine du thème**
avec le jeton **et** le payload **embarqués en base64** (aucune donnée personnelle dans
l'URL ni les logs), puis appelé en **GET** :

```bash
TOKEN=$(openssl rand -hex 16)
B64=$(base64 -w0 tools/.e2e-identity.json)
sed -e "s/REPLACE_WITH_TOKEN/$TOKEN/" -e "s#B64PAYLOAD_PLACEHOLDER#$B64#" \
    tools/e2e-orders.php > /tmp/_e2e-orders.php
# puis : dépôt FTPS « put -O . /tmp/_e2e-orders.php », appel, puis suppression FTPS.
curl -sS "https://www.luziapi.fr/wp-content/themes/luziapi/_e2e-orders.php?k=$TOKEN"
```

> À défaut de payload embarqué, le script lit aussi le corps d'une requête POST.

En cas de doute sur un résidu, relancer avec `options.cleanup_only: true` dans l'identité.
