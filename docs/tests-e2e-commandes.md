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

| Clé | Scénario | Ce qui est vérifié |
| --- | --- | --- |
| `delivery` | Commande **livraison** (`free_shipping`), paiement à la remise | mode « delivery » ; e-mails confirmée → en cours de livraison → terminée ; **« prête au retrait » bloqué** ; version CGV estampillée |
| `pickup` | Commande **retrait** (`local_pickup`) | mode « pickup » ; e-mail « prête au retrait » ; **« en cours de livraison » bloqué** |
| `cancel` | **Annulation** pour non-paiement (virement/WERO `bacs`) | e-mail « en attente » ; 2 actions planifiées (rappel + expiration) ; rappel envoyé ; expiration → commande annulée + **stock remis** + e-mail d'annulation |
| `paid_on_time` | Virement **payé à temps** | échéance planifiée à la mise en attente, puis **déprogrammée** au paiement |
| `cancel_no_reason` | **Annulation manuelle sans motif** | e-mail d'annulation **non envoyé** + note « motif obligatoire absent » |

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
    "scenarios": ["delivery", "pickup", "cancel", "paid_on_time", "cancel_no_reason"]
  }
}
```

- `send_emails` : `false` = *dry-run* (e-mails journalisés mais **non expédiés**) ;
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

L'accès prod se fait sans SSH, par script à jeton (voir
[prod-o2switch.md](prod-o2switch.md) et [AGENTS.md](../AGENTS.md) § 4). En prod, mettre
`send_emails: true` pour **recevoir réellement** les e-mails de test (~12 par run complet).

1. Injecter un jeton à usage unique dans une copie du script (remplacer
   `REPLACE_WITH_TOKEN`), la déposer en FTPS dans `tools/`.
2. Appeler en HTTPS en **POST** (le payload passe par le corps, jamais par l'URL, pour
   ne pas laisser de données personnelles dans les logs d'accès) :

   ```bash
   curl -sS -X POST \
     "https://www.luziapi.fr/wp-content/themes/luziapi/tools/e2e-orders.php?k=<JETON>" \
     -H 'Content-Type: application/json' \
     --data @tools/.e2e-identity.json
   ```
3. Lire le JSON de résultat (`summary`, `results`, `mails_logged`, `cleanup`).
4. **Supprimer le script** déposé (`rm` FTPS) et vérifier qu'il renvoie 404.

En cas de doute sur un résidu, relancer en `cleanup_only: true`.
