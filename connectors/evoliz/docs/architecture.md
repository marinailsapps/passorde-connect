# Architecture du connecteur Evoliz

_Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma._

Les chemins `src/…` de cette page sont ceux du connecteur, sous `connectors/evoliz/`, sauf `src/Licence` et `src/Http`, qui sont le cœur commun de Passorde Connect (`src/` à la racine du paquet), avec la table `passorde_requests` et le registre des connecteurs.

Un composant de Passorde, sur le serveur de l'entreprise, sans service intermédiaire entre l'application et Evoliz. **Bons de livraison uniquement, jamais de factures.**

## Couches

```text
Application hôte
   │  verbes et brouillons                commandes        contrats
   ▼                                          ▼                ▼
Écritures  src/Writes, src/Drafts      Exécutions src/Sync ──► Destinations src/Sinks
   │                                          │                  (miroir par défaut)
   │                                          ▼
   │                                    Lecture src/Reads, src/Records
   ▼                                          ▼
RequestRunner + RetryPolicy ──► EvolizApi  src/Api   ◄── gardes : écritures, chemins interdits, licence
                                              │                            ▲
                                              ▼                            │
                                       API Evoliz (ou connectors/evoliz/mock)    Licence src/Licence
```

| Couche | Rôle | Dépend de |
| --- | --- | --- |
| `src/Licence` | Vérifie le fichier signé, hors ligne | Rien |
| `src/Api` | Connexion, gardes, pagination, limite de débit, reprises, journal | Licence |
| `src/Records`, `src/Reads` | Objets typés et listes paresseuses | Api |
| `src/Sinks` | Contrats de destination et miroir par défaut | Records |
| `src/Sync` | Exécutions, verrous, historique, planification | Reads, Sinks |
| `src/Drafts`, `src/Writes` | Brouillons, verbes d'écriture, résultats typés | Api, Records, Sinks |
| `src/Console`, `src/Http` | Commandes et page de licence | Tout ce qui précède |

## Tables

Toutes sont créées par des migrations à publier.

| Table | Contenu | Écrite par |
| --- | --- | --- |
| `passorde_requests` | Une ligne par appel d'un verbe : corps, réponse, tentatives, erreur | Écritures |
| `passorde_runs` | Une ligne par exécution : type, période, statut, lignes vues et modifiées, identifiant de l'application | Exécutions |
| `passorde_clients` | Clients | Miroir |
| `passorde_contacts` | Contact principal de chaque client | Miroir |
| `passorde_articles` | Articles et stock | Miroir |
| `passorde_paytypes` | Moyens de paiement | Miroir |
| `passorde_users` | Utilisateurs | Miroir |
| `passorde_deliveries` | Bons de livraison | Miroir |
| `passorde_delivery_lines` | Lignes des bons | Miroir |

Pour lire `passorde_requests` et `passorde_runs`, voir le [dépannage](troubleshooting.md).

## Le simulateur

`connectors/evoliz/mock` ne fait pas partie du code exécuté par le paquet et ne dépend de rien : voir [simulateur](mock.md).

## Module de licence

La licence est celle du cœur de Passorde Connect (`src/Licence` à la racine du paquet) : une clé d'une ligne (`pck1.…`), signée Ed25519 et vérifiée hors ligne, qui doit lister ce connecteur (`evoliz`). Le connecteur la vérifie avant chaque appel : dans le client, dans chaque écriture et pour le jeton. Format : [docs/licence-key.md](../../../docs/licence-key.md).

## États de licence

Valide, période de grâce, expirée ou invalide, chacun avec un motif lisible.

## Transport Evoliz

Tout passe par `Passorde\Api\Contracts\EvolizApi`, implémenté par `EvolizClient` dans `src/Api` : `get()`, `paginate()` et `send()`. Cette couche ne connaît aucun verbe métier.

### Gardes

Avant tout envoi, et avant même la connexion, dans cet ordre :

1. **Écritures** : si `PASSORDE_WRITES_ENABLED` n'est pas activé, toute méthode autre que GET lève `WritesDisabledException`, qui porte la méthode et le chemin.
2. **Chemins interdits** : `ForbiddenPaths::ALWAYS` (`/invoices`, `/deliveries/{id}/invoice`, `/invoice`, `/progress-invoice`, `/credits`, `/recoveries`) lève `ForbiddenPathException`, y compris pour une lecture et quelle que soit l'écriture du chemin : casse, encodage `%69nvoices`, chemin `/api/...` complet. `PASSORDE_EVOLIZ_FORBIDDEN_PATHS` y ajoute des chemins et ne peut pas en retirer. Toute méthode autre que GET sur `/deliveries/{id}`, et en dessous, est refusée de même : un bon n'est ni modifié, ni supprimé, ni envoyé. La comparaison se fait sur des segments entiers, n'importe où dans le chemin ; `*` ou `{nom}` remplace un segment.
3. **Licence** : `LicenceGuard` bloque chaque appel, lectures comprises, si la licence est expirée ou invalide. La page de réglages et `passorde:status` n'utilisent pas l'API et continuent de fonctionner.

Une URL complète ou un chemin contenant `..` lève `InvalidPathException`. Les redirections ne sont pas suivies : le jeton ne quitte pas l'hôte configuré et une redirection ne peut pas atteindre un chemin interdit.

### Jeton

`POST {base}/api/login` renvoie un jeton valable 20 minutes. `EvolizToken` le garde 19 minutes dans le cache de Laravel (`PASSORDE_CACHE_STORE`, sinon le cache par défaut). Un 401 oublie le jeton, reconnecte et rejoue l'appel une seule fois. La clé secrète et le jeton ne sont jamais écrits dans un journal ; la clé est marquée `#[\SensitiveParameter]` et absente des traces d'un échec de connexion.

### Portée société

Un chemin relatif devient `/api/v1/companies/{id}/...`. Un chemin qui commence par `/api/` part tel quel.

### Limites

Evoliz autorise 100 appels par minute et par IP. Après chaque réponse, `RateLimitMonitor` lit `X-RateLimit-Remaining` : l'état va au niveau debug ; sous 10 appels restants, un avertissement est écrit et le processus attend 60 s. Un 429 attend `Retry-After` (60 s sans en-tête lisible, 60 s au plus) et rejoue l'appel une seule fois. Si le 429 persiste, `send()` le renvoie tel quel. Ces attentes bloquent le processus : les appels ont leur place dans des tâches de file d'attente plutôt que dans une requête web.

`paginate()` produit les pages une à une et s'arrête à `meta.last_page`. Au-delà de `evoliz.max_pages` (1 000), elle lève `PaginationLimitException` : s'arrêter sans rien dire ferait passer une liste partielle pour complète.

### Erreurs

`send()` renvoie la réponse brute, sans exception sur 4xx ou 5xx ; une connexion impossible lève `ConnectionException`. `get()` et `paginate()` lèvent `ApiException`, qui porte un `ApiError` : statut HTTP, message sur une ligne (`HTTP 400 — champ : message ; champ2 : message`) et `fieldErrors` par champ. Le message vient de `message` (texte ou `{champ: [messages]}`), sinon de `error`, sinon du corps brut tronqué.

### Reprises

`RetryPolicy` reprend les 429, les 5xx et les erreurs de connexion, jusqu'à 5 tentatives : `Retry-After` pour un 429, sinon 5 s puis le double à chaque fois, 60 s au plus. Les autres statuts sont définitifs.

Après un 5xx ou une erreur de connexion, un POST a pu aboutir. Le rappel de recherche optionnel s'exécute donc après l'attente et avant de rejouer l'appel : s'il retrouve la ressource, elle est renvoyée sans nouvel envoi. Elle s'exécute une dernière fois quand les tentatives sont épuisées. Tant que la recherche elle-même échoue, l'appel n'est pas rejoué ; chaque échec consomme une tentative. Un 429 ne déclenche pas de recherche, car rien n'a été traité.

Comme `send()` rejoue déjà une fois un 429, un 429 persistant peut aller jusqu'à 10 envois pour 5 tentatives.

### Journal des requêtes

`RequestRunner` associe `EvolizApi`, `RetryPolicy` et `Contracts\RequestLog` ; les verbes d'écriture l'utilisent, lectures comprises. Les `get()` et `send()` directs ne sont pas journalisés, pour ne pas remplir la table à chaque interrogation.

Le pilote `PASSORDE_REQUEST_LOG_DRIVER` vaut `database` (table `passorde_requests`, migration à publier) ou `null` ; Passorde peut aussi lier sa propre implémentation. Une ligne passe de `running` à `succeeded` ou `failed`, avec le corps envoyé, le statut HTTP, la réponse (limitée à 64 Ko), le nombre de tentatives et la dernière erreur. Elle est mise à jour après chaque tentative. Un appel refusé par une garde laisse une ligne `failed` avec 0 tentative. Les colonnes `subject_type` et `subject_id` relient les lignes aux enregistrements de l'application : voir [Écritures](writes.md#relier-le-journal-aux-enregistrements-de-lapplication).

`SecretMask` masque, avant tout enregistrement et quelle que soit l'implémentation du journal, les clés, jetons, mots de passe, coordonnées bancaires et liens publics de documents.

Une écriture impossible dans le journal produit un avertissement mais ne fait jamais échouer l'appel : un POST réussi ne doit pas être renvoyé parce que sa trace n'a pas pu être écrite.

## Lecture

### Enregistrements

`src/Records` contient des objets en lecture seule, construits par `fromRow()`, qui gardent la ligne d'origine dans `raw` : `ArticleRecord`, `ClientRecord`, `ContactRecord`, `PaytypeRecord`, `PaytermRecord`, `VatRateRecord`, `SaleClassificationRecord`, `DeliveryRecord`, `DeliveryLineRecord` et `UserRecord`.

- **Textes** : `Text::clean()` transforme un fragment HTML d'Evoliz en texte brut, dans cet ordre :
  1. Un entier ou un flottant devient sa chaîne (`42` → `"42"`) ; toute autre valeur qui n'est pas une chaîne donne `null`.
  2. Les octets UTF-8 invalides deviennent `?`.
  3. Les éléments `<script>` et `<style>` sont retirés avec leur contenu, remplacés par une espace.
  4. `<br>` et les fermetures `</p>`, `</div>`, `</li>`, `</tr>`, `</td>`, `</h1>` à `</h6>` deviennent une espace, pour que deux blocs ne collent pas leurs mots.
  5. Toutes les autres balises sont retirées (`strip_tags`), attributs et commentaires compris ; leur texte reste.
  6. Les entités sont décodées (HTML5, guillemets compris), après le retrait des balises : `&lt;b&gt;` donne le texte `<b>`, pas une balise.
  7. Chaque suite de blancs et de séparateurs Unicode (espace, tabulation, saut de ligne, espace insécable U+00A0 ou `&nbsp;`, espace fine insécable U+202F…) devient une seule espace.
  8. Les espaces de début et de fin sont retirées ; un texte vide donne `null`.

  Le résultat est du texte brut, pas du HTML sûr : il est à échapper à l'affichage. `Text::join()` nettoie chaque partie ainsi et joint celles qui ne sont pas vides ; `Text::email()` nettoie, met en minuscules et valide. `designationRaw` garde la désignation telle qu'Evoliz la stocke.
- **Montants, taux et quantités** : des chaînes à deux décimales (`Money`), jamais des flottants. Un prix ou un total absent vaut `0.00` ; un taux de TVA ou un stock absent reste `null`.
- **Courriels** : en minuscules et validés, sinon `null`.
- **Facturé** : statut 20 ou 24, ou `status_dates.invoice` renseigné.
- **Ligne gratuite** : prix unitaire nul.
- Un champ `enabled` absent vaut actif. Une ligne sans identifiant est ignorée avec un avertissement.

### Lecteurs

`src/Reads` passe uniquement par `EvolizApi`, en GET, avec `per_page=100` et une pagination paresseuse. `ReadArticles::all()` et `one()`, `ReadClients::all()`, `ReadPaytypes`, `ReadPayterms`, `ReadVatRates`, `ReadSaleClassifications`, `ReadDeliveries::window()` et `one()`, et `ReadUsers::all()`.

- `ReadClients` lit `/contacts-clients` une fois et rattache à chaque client son contact principal : le premier contact actif, les favoris d'abord.
- `ReadDeliveries` n'utilise jamais le filtre `search`, qu'Evoliz refuse avec un 400. Les lignes viennent de `items`, dans la réponse. `ReadDeliveries::one(int $id)` lit `GET /deliveries/{id}`, passe par les mêmes gardes (licence, chemins interdits), donne un `DeliveryRecord` construit comme ceux de la période, lignes comprises, et `null` sur un 404 ; toute autre erreur lève `ApiException`.
- `ReadUsers` est au mieux : un 401 ou 403 de `/users` donne une liste vide et un avertissement. Des identifiants refusés (`LoginFailedException`), un refus après les premières lignes ou toute autre erreur restent des échecs.

## Destinations et miroir

L'interface offerte à Passorde, ce sont les contrats de `Passorde\Sinks\Contracts` : `ArticleSink`, `ClientSink`, `PaytypeSink`, `UserSink` et `DeliverySink`. Le miroir n'en est que l'implémentation par défaut : Passorde lie la sienne pour recevoir les données dans ses propres tables.

```php
$this->app->bind(\Passorde\Sinks\Contracts\ArticleSink::class, MonCatalogue::class);
```

`upsert()` reçoit jusqu'à 100 enregistrements et renvoie le nombre de lignes réellement modifiées. `deactivateMissing()` (et `removeMissing()` pour les bons de livraison) reçoit les identifiants vus, seulement après une liste complète et non vide.

Le miroir compte les tables `passorde_clients`, `passorde_contacts`, `passorde_articles`, `passorde_paytypes`, `passorde_users`, `passorde_deliveries` et `passorde_delivery_lines`. Chacune porte `evoliz_id` (unique), `synced_at`, `is_active` et les horodatages, à l'exception des lignes de bons : Evoliz ne leur donne pas d'identifiant, elles sont uniques par bon et position.

- **Modifications** : `row_hash` résume les colonnes du miroir. `updated_at` ne bouge que si ce résumé change ; `synced_at` bouge à chaque passage. Un nouveau champ côté Evoliz ne touche donc aucune ligne. `raw` n'est pas stocké.
- **Disparitions** : une ligne absente d'une liste complète passe à `is_active = false`. Elle n'est jamais supprimée et redevient active si elle revient. Une liste vide ou en échec ne désactive rien.
- **Contacts** : seul le contact principal de chaque client est conservé. Un contact qui cesse de l'être, ou dont le client disparaît, passe inactif. `enabled` reflète Evoliz, `is_active` la présence dans la liste.
- **Bons de livraison** : les lignes sont réécrites toutes ensemble, avec un seul horodatage, quand `lines_hash` change ; le bon compte alors comme modifié. Un bon daté dans la période et absent de la réponse est supprimé logiquement (`deleted_at`, `is_active = false`) et restauré s'il revient. Une période vide ne supprime rien : un dernier bon supprimé dans une période devenue vide reste donc dans le miroir jusqu'à ce qu'un autre bon apparaisse dans cette période.

## Exécutions

`src/Sync` contient un exécutant par type : `SyncClients`, `SyncArticles`, `SyncPaytypes`, `SyncUsers` et `SyncDeliveries`. Chaque exécution écrit une ligne dans `passorde_runs` : type, période, statut (`queued`, `running`, `succeeded`, `failed`, `skipped`), début, fin, lignes vues et modifiées, erreur.

- **Verrou** : un verrou de cache par type. Une seconde exécution du même type est enregistrée `skipped`, sans appel à Evoliz. Le verrou expire après `passorde-connect.sync.lock_seconds` (1 h) si le processus meurt ; l'exécution suivante marque alors `failed` la ligne restée `running`.
- **Licence** : vérifiée avant le premier appel. Une licence expirée ou invalide donne une exécution `failed` avec le motif, sans aucun appel.
- **Identifiant de l'application** : chaque `run()` accepte un `?int $hostRunId` facultatif, `SyncArticles::run(hostRunId: $tache->id)` ou `SyncDeliveries::run($periode, $tache->id)`. Il est écrit dans `passorde_runs.host_run_id` (colonne indexée, ajoutée par la migration `2026_01_01_000003_add_host_run_id_to_passorde_runs_table`) pour chaque ligne de l'exécution, `skipped` comprise, et se retrouve dans `RunResult::$hostRunId`. Sans identifiant, la colonne n'est pas écrite, et la migration n'est pas requise.
- **Période des bons** : par défaut les 7 derniers jours et les 31 prochains, car un document peut être daté dans le futur. Au-delà de 100 jours, une exécution par mois civil, sous un même verrou, en s'arrêtant au premier échec.

`passorde:sync` écrit une ligne par exécution et sort avec un code non nul en cas d'échec ; une exécution `skipped` n'est pas un échec. `passorde:status` affiche la dernière exécution de chaque type avant le bloc licence.

## Écritures

`src/Writes` contient `CreateClient`, `UpdateClient`, `UpsertContact`, `CreateArticle`, `CreateDelivery` et `AdjustStock`, qui prennent les brouillons de `src/Drafts` et renvoient un résultat typé de `src/Writes/Results`. Voir [Écritures](writes.md). `src/Writes/Support` est interne.

## Périmètre de synchronisation

Clients, catalogue et stock, bons de livraison ; jamais les factures.
