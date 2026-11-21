# Écritures

_Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma._

Six verbes dans `Passorde\Writes`, résolus par le conteneur de Laravel. Le connecteur crée les bons de livraison et ne fait rien d'autre sur eux : ni facture, ni modification, ni suppression, ni envoi.

Les écritures sont désactivées tant que `PASSORDE_WRITES_ENABLED` n'est pas activé. Les attentes de reprise et de limite de débit bloquent le processus : ces appels ont leur place dans des tâches de file d'attente.

## Résultats

Chaque verbe renvoie un objet de `Passorde\Writes\Results`, qui implémente `WriteResult` (`succeeded(): bool`).

| Résultat | Sens | Contenu |
| --- | --- | --- |
| `Created` | Créé par cet appel | `record` |
| `AlreadyExists` | Déjà présent, rien n'a été créé | `record`, `afterUncertainResponse` |
| `Updated` | Modification appliquée | `record`, `afterUncertainResponse` |
| `StockAdjusted` | Stock écrit | `record`, `before`, `written`, `after`, `confirmed`, `afterUncertainResponse` |
| `Refused` | La demande est en cause ; la renvoyer telle quelle ne changerait rien | `status`, `message`, `fieldErrors` |
| `Failed` | Evoliz ou le réseau a échoué, ou les identifiants sont refusés | `status`, `message`, `uncertain`, et pour `AdjustStock` `before`, `written` |

- `afterUncertainResponse` : Evoliz n'a pas répondu clairement, puis une relecture a montré que l'écriture avait abouti. Pour `AlreadyExists`, l'enregistrement trouvé est très probablement celui de cet appel.
- `Failed::$uncertain` : l'écriture a peut-être abouti. Relancer `CreateClient`, `CreateArticle`, `CreateDelivery`, `UpdateClient` ou `UpsertContact` reste sûr. Pour `AdjustStock`, relancer avec la `StockExpectation` du premier essai : voir plus bas.
- `Refused::$status` vaut `null` quand le connecteur refuse lui-même une demande dont il connaît le sort. `fieldErrors` donne les messages de validation par champ, ceux d'Evoliz tels quels.

```php
$result = app(CreateDelivery::class)->run($draft);

match (true) {
    $result instanceof Created, $result instanceof AlreadyExists => $commande->lier($result->record->id, $result->record->number),
    $result instanceof Refused => $commande->signaler($result->fieldErrors),
    $result instanceof Failed => $this->release(300), // relancer est sûr
};
```

**Une lecture préalable qui échoue est un résultat, jamais une exception.** `CreateClient`, `CreateArticle`, `CreateDelivery` et `UpsertContact` cherchent avant de créer, `AdjustStock` lit le stock avant d'écrire. Si cette lecture ne peut pas se faire, rien n'est écrit et le verbe rend :

- `Failed` avec `uncertain: false` après une erreur 5xx, une panne réseau, un 401 ou un 429 une fois les tentatives épuisées, et quand une liste dépasse `passorde-connect.evoliz.max_pages` sans être lue jusqu'au bout. `status` et `message` sont ceux de l'erreur d'Evoliz (`status` à `null` sans réponse). Relancer plus tard est sûr : c'est le même cas que le `release(300)` ci-dessus.
- `Refused` quand Evoliz refuse la lecture elle-même (un autre 4xx) : la relancer telle quelle ne changerait rien.

Les `ApiException` et `PaginationLimitException` que mentionnent les classes internes (`WriteCalls`) ne sortent pas d'un verbe.

Ne sont pas des résultats, mais des exceptions levées avant tout envoi : `WritesDisabledException`, `ForbiddenPathException`, `LicenceException`. Les lectures préalables ont lieu même écritures désactivées, si bien qu'un appel peut renvoyer `AlreadyExists` dans ce mode. Un brouillon incohérent lève `Passorde\Drafts\InvalidDraftException` (`fieldErrors`) dès sa construction.

## Clients et contacts

```php
use Passorde\Drafts\ClientDraft;
use Passorde\Writes\CreateClient;

$result = app(CreateClient::class)->run(new ClientDraft(
    name: 'Exemple Test',
    addressLine: "1 chemin de l'Exemple",
    postcode: '00100',
    town: 'EXEMPLEVILLE',
    phone: '00 00 00 00 01',
));
```

- `addressLine`, `postcode` et `town` sont facultatifs : un client sans adresse connue se crée avec `new ClientDraft(name: 'Exemple Test')`. Ce qui est `null` ou vide est omis de la requête ; le bloc `address` part toujours, avec au moins `iso2`. La documentation d'Evoliz exige `postcode` et `town`, mais des clients créés sans eux ont été acceptés : voir [point 15](api-to-confirm.md).
- `vatNumber` vaut `NC` par défaut : Evoliz exige ce champ.
- `code` est le code à préférer côté Evoliz. Sans lui, il vient des lettres du nom, dix au plus. `GET /clients?code=` le cherche d'abord : le même client (nom et code postal, un code postal absent valant vide) donne `AlreadyExists` ; un autre client qui l'occupe fait passer au code suivant (`EXEMPLETE2`).
- Evoliz ne vérifie presque rien sur un client (un code postal à quatre chiffres ou un téléphone en toutes lettres sont enregistrés). Ces contrôles reviennent à l'application.

```php
use Passorde\Drafts\ClientPatch;
use Passorde\Writes\UpdateClient;

app(UpdateClient::class)->run(601, new ClientPatch(phone: '00 00 00 00 03', mobile: ''));
```

Dans `ClientPatch`, `null` laisse un champ tel quel et un texte vide l'efface. `addressLine` remplace toute l'adresse de rue : une deuxième ou troisième ligne non fournie est effacée. `addressLine: ''` efface la rue entière (`address.addr`, `addr2` et `addr3` à `null`), pour un client dont l'adresse n'est plus connue. Le code postal et la ville, eux, ne s'effacent pas : un texte vide lève `InvalidDraftException`, car la documentation d'Evoliz déclare `addr` effaçable (`nullable`) sur ce PATCH, mais ni `postcode` ni `town`, et l'essai n'a pas été fait sur un compte réel ([point 9](api-to-confirm.md)).

Evoliz peut répondre 500 et appliquer quand même, ou répondre 500 sans appliquer. Le client est donc relu avant toute reprise : s'il contient la modification, c'est un succès, sinon la demande repart.

```php
use Passorde\Drafts\ContactDraft;
use Passorde\Writes\UpsertContact;

app(UpsertContact::class)->run(601, new ContactDraft(lastName: 'Exemple', email: 'camille@exemple.org'));
```

La cible est le contact désigné par `contactId`, sinon celui qui a cette adresse, sinon le contact principal ; un client sans contact en reçoit un, favori s'il a une adresse. Même règle pour `null` et le texte vide.

`lastName` est facultatif pour une modification : `null` le laisse tel quel, si bien qu'une correction de l'adresse seule n'a pas besoin du nom ni d'une relecture du contact, et la requête ne porte que `email` :

```php
app(UpsertContact::class)->run(601, new ContactDraft(email: 'nouvelle@exemple.org', contactId: 801));
```

Il reste obligatoire pour créer un contact : si le client n'en a aucun et que le brouillon n'a pas de `lastName`, `run()` lève `InvalidDraftException` (`fieldErrors['lastName']`) sans rien envoyer. Un brouillon sans aucun champ à écrire est refusé dès sa construction (`fieldErrors['contact']`). L'adresse d'un contact favori peut être remplacée, jamais effacée : ce cas donne `Refused` sans aucun envoi, comme un contact favori sans adresse.

## Articles

```php
use Passorde\Drafts\ArticleDraft;
use Passorde\Writes\CreateArticle;

app(CreateArticle::class)->run(new ArticleDraft(
    reference: 'ART-3001',
    designation: 'Fournitures & matériel',
    unitPriceHt: '12.50',
    vatRate: 5.5,
    stockManaged: true,
    stockQty: 7,
));
```

`designation` est du texte brut, échappé par le connecteur. Le stock n'est envoyé que derrière `stockManaged`. L'API d'Evoliz ne supprime pas d'article : la référence est donc cherchée avant toute création, entière et sans tenir compte de la casse.

## Bons de livraison

```php
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Writes\CreateDelivery;

app(CreateDelivery::class)->run(new DeliveryDraft(
    clientId: 601,
    externalNumber: 'CMD-2026-001',
    documentDate: now()->addDays(5),
    paytermId: 7,
    paytypeId: 2,
    comment: 'Commande CMD-2026-001',
    lines: [
        new DeliveryLineDraft(articleId: 3001, quantity: 2, unitPriceHt: '12.50'),
        new DeliveryLineDraft(articleId: 3001, quantity: 1, unitPriceHt: 0, designationOverride: $article->designationRaw.' / offert'),
        new DeliveryLineDraft(articleId: 3002, quantity: 1, designationOverride: DeliveryLineDraft::withComment($port->designationRaw, 'Livrer avant 10 h')),
    ],
));
```

Evoliz enregistre un bon à sa création, avec un numéro définitif, et son API ne le supprime pas. D'où ces règles :

- **Référence** : `externalNumber` va dans `external_document_number` et identifie le bon. Elle doit être unique pour le client.
- **Recherche avant création** : le filtre `search` d'Evoliz ne fonctionne pas. Le connecteur lit `GET /deliveries?clientid=…&period=custom&date_min=…&date_max=…`, toutes pages, et compare le numéro exactement. La période couvre 366 jours avant la date du document ou aujourd'hui et 31 jours après (`passorde-connect.writes.delivery_lookup`), car la date d'un bon peut changer dans Evoliz. Une liste qui ne peut pas être lue jusqu'au bout donne `Failed`, jamais une création.
- **Après une réponse incertaine** (5xx ou réseau) : même recherche avant de renvoyer, et une dernière fois quand les tentatives sont épuisées. Tant que la recherche ne répond pas, rien ne repart.
- **Verrou** : un verrou de cache par client et référence empêche deux processus de créer le même bon (`passorde-connect.writes.lock_seconds`, `lock_wait_seconds`). Un cache partagé entre les processus est donc nécessaire. Faute de verrou à temps : `Failed` avec `uncertain`.
- **Lignes** : chacune porte un `articleId`. Sur une ligne libre, Evoliz perd la TVA sans rien dire ; la TVA et le classement viennent toujours de l'article. `unitPriceHt: 0` rend la ligne gratuite ; `null` prend le prix de l'article.
- **Désignation** : `designationOverride` est du HTML et remplace tout le texte de l'article, numéros compris. Partir de `ArticleRecord::$designationRaw`. `withComment()` ajoute un commentaire sous la désignation, séparé par `<br />` et un saut de ligne.
- **Conditions** : `paytermId` est obligatoire et `term.recovery_indemnity` vaut `true` par défaut, car Evoliz n'applique pas ici le réglage de la société.
- **Date** : elle peut être dans le futur ; au-delà de 30 jours, Evoliz refuse (`Refused`). Un objet date est formaté tel quel : attention à son fuseau.

## Stock

```php
use Passorde\Writes\AdjustStock;

$result = app(AdjustStock::class)->run(3001, -1);
```

Evoliz ne connaît pas de mouvement de stock : son `PATCH` écrase la quantité, et cet ajustement n'apparaît pas dans ses « Mouvements de stock ». `AdjustStock` lit donc le stock, calcule, écrit, puis relit ; chaque appel est journalisé (`stock.read`, `stock.adjust`, `stock.verify`). Un appel fait un seul ajustement.

- Un bon créé dans Evoliz entre la lecture et l'écriture serait écrasé. La dernière relecture le signale : `confirmed` vaut `false`, `after` diffère de `written` et un avertissement est écrit.
- Après une réponse incertaine, si le stock relu est celui écrit, c'est un succès ; s'il n'a pas bougé, la même valeur repart ; s'il vaut autre chose, rien ne repart et le résultat est `Failed` avec `uncertain`.
- Evoliz ne garde aucune clé qui permettrait de reconnaître un ajustement déjà fait. Un `Failed` venu d'`AdjustStock` porte donc ce qui a été tenté : `before`, le stock lu, et `written`, la valeur envoyée (`null` tous deux quand rien n'a été envoyé). L'application les garde et relance avec le même ajustement :

  ```php
  use Passorde\Drafts\StockExpectation;

  $result = app(AdjustStock::class)->run(3001, -1, new StockExpectation($echec->before, $echec->written));
  ```

  Si le stock lu vaut alors `written`, l'essai précédent a abouti : aucun `PATCH` ne part, et le résultat est `StockAdjusted` avec `confirmed` et `afterUncertainResponse` à `true`, `before` et `written` étant ceux de l'essai précédent. Sinon l'ajustement repart du stock lu, comme un appel sans attente : si quelqu'un d'autre a bougé le stock entre-temps, c'est de cette valeur que part le calcul. Un mouvement venu d'ailleurs qui amènerait le stock exactement sur `written` serait pris pour l'essai précédent : la relance doit suivre l'échec de près.
- Un article sans gestion de stock donne `Refused`.

## Journal et miroir

Chaque appel d'un verbe, lectures comprises, écrit une ligne dans `passorde_requests` avec son corps et sa réponse. Sont masqués par `SecretMask` : clés, jetons, mots de passe, coordonnées bancaires et lien public du document (`webdoc`). Un appel refusé par une garde laisse une ligne `failed` avec 0 tentative. Les lecteurs de `src/Reads` et `passorde:sync` n'y écrivent pas.

### Relier le journal aux enregistrements de l'application

C'est la façon prévue de retrouver, pour le support, les appels faits pour une commande ou tout autre enregistrement de l'application :

```php
use Passorde\Api\RequestContext;

$result = RequestContext::subject('order', $commande->id, fn () => app(CreateDelivery::class)->run($draft));
```

Chaque appel journalisé pendant la fonction, lectures préalables, essais et relectures compris, reçoit `subject_type = 'order'` et `subject_id = $commande->id` dans `passorde_requests` ; `subject()` rend ce que la fonction rend. Les sujets s'emboîtent : le plus intérieur s'applique, et le précédent revient à sa sortie, exception comprise. Hors de `subject()`, les deux colonnes restent vides. Le type est libre (alias de morphisme ou nom de classe, 255 caractères au plus) ; l'identifiant est un entier positif, ou une chaîne qui en contient un, car la colonne est un entier non signé : autre chose lève `InvalidArgumentException` avant tout appel. Les lecteurs de `src/Reads` et `passorde:sync` n'écrivent pas dans ce journal. Un `RequestLog` propre à l'application lit le sujet courant par `RequestContext::current()`.

```sql
SELECT id, kind, status, http_status, last_error, created_at
FROM passorde_requests WHERE subject_type = 'order' AND subject_id = 1234 ORDER BY id;
```

### Destinations

**Un verbe remet l'enregistrement aux destinations de l'application avant de rendre son résultat.** Après une écriture réussie (`Created`, `Updated`, `StockAdjusted`) comme pour un `AlreadyExists`, l'enregistrement passe aux contrats `ArticleSink`, `ClientSink` ou `DeliverySink` (voir [architecture](architecture.md)), dans le même processus, de façon synchrone et sous le verrou du verbe : quand `run()` rend la main, la destination a déjà reçu l'enregistrement, ou l'a refusé avec un avertissement. Une destination ne doit donc pas rappeler le même verbe pour le même objet, qui attendrait son propre verrou. Un `Refused` ou un `Failed` n'appelle aucune destination. Une destination en échec produit un avertissement et ne fait jamais échouer le verbe : l'écriture a eu lieu, et la synchronisation suivante rattrape. Une écriture sur un client relit ses contacts, car une destination comprend un client sans contact comme un contact disparu ; si cette lecture échoue, la destination n'est pas appelée.

**Sans miroir.** `passorde-connect.writes.mirror` (`PASSORDE_WRITES_MIRROR`, activé par défaut) à `false` coupe tout cela : aucune destination n'est appelée par un verbe, et les relectures faites pour elles disparaissent, soit `GET /contacts-clients?clientid=…` après chaque écriture ou recherche de client, et `GET /clients/{id}` puis ses contacts après chaque écriture de contact. Une écriture de client ou de contact coûte ainsi une ou deux requêtes de moins. En contrepartie, les destinations ne reçoivent rien avant la synchronisation suivante : une lecture faite entre-temps par l'application dans ses propres tables voit l'état d'avant l'écriture. Le résultat du verbe reste complet, sauf pour un client : `ClientRecord::$contact` y vaut alors `null`, ses contacts n'ayant pas été lus. Les relectures qui servent à décider (recherche avant création, vérification après un 500, relecture du stock) ont lieu dans les deux cas. À réserver au cas où les destinations de Passorde ignorent ces enregistrements, ou où la synchronisation lui suffit.

## Comportements à confirmer

Plusieurs de ces règles reposent sur des comportements d'Evoliz qui restent à vérifier sur un compte réel : voir [cette liste](api-to-confirm.md), à commencer par le délai d'apparition d'un bon dans la liste. Pour s'exercer sans risque, voir le [simulateur](mock.md).
