# Comportements de l'API à confirmer sur un compte réel

_Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma._

Le connecteur s'appuie sur ces points sans les avoir vérifiés de bout en bout sur un compte réel. Les tests les supposent, le [simulateur](mock.md) aussi. Ils sont à contrôler à la première installation réelle.

**Prudence** : un bon de livraison créé sur un compte réel est enregistré avec un numéro définitif, et l'API ne le supprime pas. Les articles non plus. Les points marqués « écriture » créent des objets réels : utiliser un client de test, et ne jamais facturer ces bons.

| Essai | Points |
| --- | --- |
| Clés en lecture seule | 1, 13, 14 |
| Clés en écriture | 2, 3, 4, 6, 8, 9, 10, 11, 12, 15 |
| Interface d'Evoliz, puis lecture | 5 |
| Ne se provoque pas : à observer | 7 |

## Par ordre d'importance

### 2. Un bon est-il listé aussitôt après sa création ? *(écriture)*

- **Hypothèse** : après un `POST /deliveries` resté sans réponse, `GET /deliveries` montre le bon tout de suite.
- **Si c'est faux** : une reprise ne le voit pas et crée un doublon. C'est le risque principal.
- **Essai** : créer un bon, le chercher dans la liste aussitôt et à quelques secondes d'écart.

### 1. La recherche combine-t-elle `clientid` et une période personnalisée ? *(lecture)*

- **Hypothèse** : `clientid`, `period=custom`, `date_min` et `date_max` s'appliquent ensemble, sur environ 400 jours.
- **Si c'est faux** : la recherche avant création manque un bon existant, ou échoue, ce qui donne `Failed` et aucune création.
- **Essai** : comparer avec et sans `clientid` sur un client connu ; essayer une période de 400 jours.

### 3. `external_document_number` est-il vraiment unique ? *(écriture)*

- **Hypothèse** : la documentation le dit unique ; le connecteur le traite comme unique par client.
- **Si c'est faux** : un numéro déjà pris chez un autre client peut donner un 400 (`Refused`). Si rien n'est contrôlé, seule la recherche du connecteur protège.
- **Essai** : le même numéro sur deux clients, puis deux fois sur le même.

### 7. Que fait `POST /deliveries` sur un 5xx ou un délai dépassé ? *(à observer)*

- **Hypothèse** : le bon a pu être créé ou non ; la recherche tranche.
- **État** : jamais observé réellement. Le chemin de reprise n'est testé que sur des simulations.
- **Essai** : à surveiller dans `passorde_requests` (`delivery.create` avec `attempts` supérieur à 1) ; voir [dépannage](troubleshooting.md).

### 13. Comment répond une clé en lecture seule ? *(lecture)*

- **Hypothèse** : un 403 sur les écritures, qui donne `Refused`. Le connecteur traite tout 4xx comme un refus, sauf 401 et 429 ; un 401 donne `Failed`.
- **Essai** : avec cette clé, tenter une écriture sans conséquence sur un client de test, par exemple son téléphone, et lire la ligne de `passorde_requests`.

### 14. Le masquage du journal suffit-il ? *(lecture)*

- **Hypothèse** : `webdoc`, `bank_information.*`, `term.bank.iban` et `term.bank.bic` sont les champs sensibles. Les liens de `file` et `links` ne sont pas masqués, car ils exigent un jeton pour s'ouvrir.
- **Essai** : relire des lignes de `passorde_requests` issues de vraies réponses ; ouvrir un de ces liens sans jeton.

## Bons de livraison

### 4. Encodage de `external_document_number` *(écriture)*

- **Hypothèse** : le connecteur compare après décodage, en respectant la casse.
- **Essai** : une référence contenant `&`, relue ensuite ; la même en minuscules.

### 5. Un bon modifié dans l'interface *(interface, puis lecture)*

- **Hypothèse** : il reçoit un nouveau `deliveryid`, garde son `external_document_number` et reste filtrable par `clientid`.
- **Si c'est faux** : le bon n'est plus reconnu, et une nouvelle demande crée un doublon.

### 6. Variantes de `POST /deliveries` *(écriture)*

- Une date dans le futur envoyée par l'API. Des bons datés d'avance ont été vus dans des listes, et le refus au-delà de 30 jours a été vu ; une création réussie à date future, non.
- `unit_price` omis, connu seulement par un exemple du support d'Evoliz.
- Une `quantity` décimale.

## Clients et contacts

### 8. Champs de `POST /clients` *(écriture)*

- `mobile`, `addr2`, `addr3`, et `type` autre que `Professionnel`.
- Un `code` déjà pris : la réponse est-elle bien un 400 ?
- La précision de `GET /clients?code=` : le connecteur compare exactement, de toute façon.

### 15. Quels champs d'adresse `POST /clients` exige-t-il vraiment ? *(écriture)*

- **Hypothèse** : seul `address.iso2` est exigé. La documentation d'Evoliz exige `address` avec `postcode`, `town` et `iso2`, et `addr` seulement quand la facturation électronique est activée ; une intégration antérieure omettait la rue, le code postal ou la ville absents, et ses clients ont été acceptés. Aucune réponse à une création sans code postal ni ville n'a été enregistrée.
- **Si c'est faux** : un `ClientDraft` sans code postal ou sans ville donne un 400 (`Refused`, avec `fieldErrors`) ; rien n'est créé. Le simulateur, lui, n'exige que `iso2`.
- **Essai** : sur un client de test, un `POST /clients` sans `addr`, puis un sans `postcode` ni `town` ; noter aussi l'effet de la facturation électronique sur `addr`.

### 9. Champs de `PATCH /clients/{id}` *(écriture)*

- `name`, `vat_number` et `address.iso2`. La relecture compare `iso2` à `address.country.iso2` : cette forme est vue en lecture, mais son changement après un PATCH n'a pas été vérifié.
- `address.addr: null` pour effacer la rue : `addr2: null` et `addr2: ""` ont été vus effacer leur ligne, `addr` n'a pas été essayé. Effacer `postcode` ou `town` : le connecteur le refuse, la documentation ne les déclarant pas `nullable` ; à essayer avant de l'ouvrir.

### 10. Contacts *(écriture)*

- `PATCH email: null` sur un contact non favori ; seul le cas du favori a été essayé.
- `favorite` modifié par PATCH, ou `false` à la création.
- `GET /contacts-clients/{id}` comme relecture : aucune réponse enregistrée.
- Un POST sur un client qui a déjà un favori : le favori change-t-il ?

## Articles et stock

### 11. Variantes de `POST /articles` *(écriture)*

- `stocked_quantity` différent de 0, `stock_management: false`, `nature: service`.
- Aucun `sale_classificationid`, `unit_price` entier.
- Le filtre `reference` : casse, caractères encodés.

### 12. `PATCH stocked_quantity` avec décimales et négatifs *(écriture)*

- Seules des valeurs entières ont été écrites réellement. Un stock négatif n'a été vu que comme résultat d'un bon de livraison.

## Observé sur un compte réel

Ces points ont été vus au moins une fois et n'appellent qu'une surveillance :

- `vat_number: "NC"` est accepté sur `POST /clients`, et le champ est exigé.
- `term.recovery_indemnity: true` est respecté.
- Une ligne libre perd sa TVA.
- `designation` remplace tout le texte de l'article, et `<br />` suivi d'un saut de ligne garde un commentaire sur sa propre ligne.
- `PATCH /clients/{id}` peut répondre 500 en appliquant ; un PATCH de contact peut répondre 500 sans appliquer.
- La forme des 400 : `{"error": "...", "message": {"champ": ["..."]}}`.
- `PATCH stocked_quantity` écrase la quantité.

## Une fois confirmé

Reporter chaque écart dans le code et dans le simulateur (`connectors/evoliz/mock/src/Mock.php`), pour que les tests suivent l'API réelle.
