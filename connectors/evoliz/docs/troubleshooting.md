# Dépannage

_Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma._

Trois sources à consulter d'abord : `php artisan passorde:status`, la table `passorde_runs` pour les lectures, la table `passorde_requests` pour les écritures. Les avertissements vont dans le journal de l'application.

## Licence

`php artisan passorde:status` affiche l'état et renvoie 0 (valide), 1 (grâce) ou 2 (expirée ou invalide). `php artisan passorde:licence show` donne le détail. Voir la [FAQ licence](../../../docs/licence-faq.md).

| Message ou état | Cause | Que faire |
| --- | --- | --- |
| Période de grâce | Période payée terminée depuis moins de 14 jours | Tout fonctionne encore : renouveler sur <https://apps.marinails.site/passorde#tarifs> avant le dernier jour indiqué, puis installer la clé reçue par e-mail (quand le planificateur tourne, le rafraîchissement quotidien l'installe aussi de lui-même) |
| Expirée | Période de grâce terminée | Renouveler sur <https://apps.marinails.site/passorde#tarifs> puis installer la clé reçue par e-mail ; les appels reprennent aussitôt |
| « Aucune licence n'est installée » | Aucune clé dans le stockage de licence | Coller la clé dans les réglages d'administration de Passorde (ou `php artisan passorde:licence install`) |
| « Ce texte n'est pas une clé de licence » | Clé tronquée ou autre texte collé | Copier la clé entière, une ligne qui commence par `pck1.` |
| « C'est une licence au format JSON » | Un fichier JSON a été collé à la place de la clé | Installer la clé de licence reçue par e-mail (une ligne qui commence par `pck1.`) |
| « Cette licence ne couvre pas le connecteur Passorde Connect — Evoliz » | Clé achetée pour un autre connecteur | Acheter ou faire étendre une licence qui couvre Evoliz |
| « …signée avec une clé inconnue… » | Licence signée avec une clé que cette version ne connaît pas | Mettre à jour le paquet, ou demander une clé émise pour cette version |
| « Cette licence couvre Passorde Connect jusqu'à la version… » | Version installée au-delà de `version_max` | Revenir à une version couverte ou installer une licence adaptée |
| « Les mises à jour de cette licence couvrent les versions publiées jusqu'au … inclus » | Licence à vie dont les 12 mois de mises à jour (ou la prolongation) ont pris fin avant la publication de la version installée | Prolonger les mises à jour sur <https://apps.marinails.site/passorde#prolonger>, ou installer la clé reçue par e-mail, ou revenir à une version publiée au plus tard ce jour-là |
| « La signature de la licence est incorrecte » | Clé modifiée ou mal copiée | Réinstaller la clé d'origine, sans la retoucher |
| « La licence enregistrée ne peut pas être lue » | Table `passorde_licences` absente, ou `APP_KEY` changée depuis l'installation | Exécuter les migrations ; réinstaller la clé après un changement d'`APP_KEY` |
| « La date d'émission… est dans le futur » | Horloge du serveur | Corriger l'heure du serveur |

L'installation d'une clé refusée indique le motif et conserve la licence en place. Si le rafraîchissement échoue, la clé actuelle reste et la cause est écrite dans le journal. Une exécution bloquée par la licence apparaît `failed` dans `passorde_runs` avec le motif, sans aucun appel.

La page de licence renvoie vers la connexion : elle exige par défaut les middlewares `web,auth` (`PASSORDE_ROUTE_MIDDLEWARE`). Elle répond 404 : `PASSORDE_ROUTE_ENABLED` est désactivé. Elle s'affiche sans mise en forme : la route `/{préfixe}/licence.css` est bloquée, ou une copie publiée dans `public/vendor/passorde/` est périmée ; `php artisan vendor:publish --tag=passorde-assets --force` la remplace.

## Connexion à Evoliz

`php artisan passorde:ping` renvoie 0 (connexion correcte), 1 (échec, avec le motif) ou 2 (bloqué par la licence).

| Motif | Cause |
| --- | --- |
| `Configuration Evoliz incomplète : …` | Variables `PASSORDE_EVOLIZ_*` manquantes, ou configuration en cache : `php artisan config:clear` |
| `HTTP 401 — …` à la connexion | Clés refusées |
| `HTTP 403 — …` | Mauvaise société ou droits de la clé |

## Limite de débit

Evoliz autorise 100 appels par minute et par IP. Sous 10 appels restants, le connecteur écrit l'avertissement `rate limit nearly exhausted` et attend 60 s : **un processus qui semble bloqué pendant une minute attend simplement**. Un 429 attend `Retry-After` et rejoue l'appel une fois ; s'il persiste, le verbe ou l'exécution échoue avec `HTTP 429`, sans rien casser. `passorde:ping` affiche les appels restants. Plusieurs applications sur la même IP partagent cette limite.

## « Écritures vers Evoliz désactivées »

`WritesDisabledException` : `PASSORDE_WRITES_ENABLED` n'est pas activé, ou la configuration en cache est ancienne (`php artisan config:clear`). L'écriture n'est pas partie. Les lectures préalables ont lieu quand même : un verbe peut donc répondre `AlreadyExists` dans ce mode.

## « Chemin Evoliz interdit »

`ForbiddenPathException` : le connecteur ne facture jamais. Ces chemins ne sont appelés sous aucune condition, et `PASSORDE_EVOLIZ_FORBIDDEN_PATHS` peut en ajouter, pas en retirer. Un bon de livraison n'est non plus jamais modifié, supprimé ni envoyé. Ce n'est pas une panne : c'est le code appelant qu'il faut revoir.

## « La même écriture est déjà en cours »

Un autre processus écrit la même chose, ou s'est arrêté en gardant son verrou, qui expire après `passorde-connect.writes.lock_seconds`. Relancer plus tard est sûr. Si les processus ne partagent pas le même cache, les verrous ne les protègent pas : voir `PASSORDE_CACHE_STORE`.

## Un bon en double ?

Un bon enregistré ne se supprime pas par l'API, et le connecteur ne le tentera jamais. Ne pas relancer tant que la situation n'est pas comprise.

**1. Retrouver les bons.** Après une synchronisation de la période :

```sql
SELECT evoliz_id, number, external_number, client_evoliz_id, document_date, invoiced, deleted_at
FROM passorde_deliveries
WHERE external_number = 'CMD-2026-001';
```

**2. Retrouver les envois.**

```sql
SELECT id, status, http_status, attempts, last_error, created_at
FROM passorde_requests
WHERE kind = 'delivery.create' AND payload LIKE '%CMD-2026-001%'
ORDER BY id;
```

**3. Lire le résultat.**

| Ce qu'on voit | Explication probable |
| --- | --- |
| Deux lignes `delivery.create` réussies, proches dans le temps | Des processus sans cache commun : le verrou n'a rien protégé (`PASSORDE_CACHE_STORE`) |
| Une ligne avec `attempts` supérieur à 1 et un 5xx ou une erreur réseau, deux bons | La recherche n'a pas vu le premier bon à temps : voir le point 2 des [comportements à confirmer](api-to-confirm.md) ; à signaler |
| Deux lignes éloignées dans le temps, deux bons | La date du premier bon a changé dans Evoliz et il sort de la période cherchée (`passorde-connect.writes.delivery_lookup`), ou le bon a été modifié dans l'interface |
| Une seule ligne, un seul bon de ce numéro, et un second sans référence | Le second a été saisi dans l'interface d'Evoliz |
| Le même numéro sur deux clients | Normal : la référence est unique par client |

**4. Corriger** dans l'interface d'Evoliz, sans facturer le bon en trop, puis resynchroniser : le miroir le marque supprimé.

Un `Failed` avec `uncertain` n'est pas un doublon : le bon a peut-être abouti, et relancer le même appel est sûr, car la recherche passe d'abord. Seul `AdjustStock` demande de vérifier le stock avant de relancer.

## Lire `passorde_requests`

Une ligne par appel d'un verbe d'écriture, lectures comprises. `passorde_requests` ne journalise que les appels des verbes d'écriture de `src/Writes`, y compris les lectures qu'ils font pour eux-mêmes (recherche avant création, relecture, lecture du stock). Les lecteurs de `src/Reads`, `passorde:sync` et les appels directs à `EvolizApi` n'y écrivent rien : c'est voulu, pour ne pas remplir la table à chaque synchronisation ; une exécution se suit dans `passorde_runs`. Les secrets sont masqués.

| Colonne | Sens |
| --- | --- |
| `kind` | `client.lookup`, `client.create`, `client.update`, `client.verify`, `client.read`, `contact.lookup`, `contact.create`, `contact.update`, `contact.verify`, `article.lookup`, `article.create`, `delivery.lookup`, `delivery.create`, `stock.read`, `stock.adjust`, `stock.verify` |
| `method`, `path`, `payload` | Ce qui a été envoyé |
| `http_status`, `response` | La dernière réponse, limitée à 64 Ko |
| `status` | `running`, `succeeded` ou `failed` |
| `attempts` | Nombre d'essais ; 0 si une garde a refusé l'appel |
| `last_error` | La dernière erreur |
| `subject_type`, `subject_id` | L'enregistrement de l'application pour lequel l'appel a été fait, donné par `RequestContext::subject()` ; vides sinon |

```sql
-- Ce qui a échoué récemment
SELECT id, kind, method, path, http_status, attempts, last_error, created_at
FROM passorde_requests WHERE status = 'failed' ORDER BY id DESC LIMIT 50;

-- Ce qui a dû être repris
SELECT id, kind, path, attempts, http_status, created_at
FROM passorde_requests WHERE attempts > 1 ORDER BY id DESC LIMIT 50;

-- Une ligne restée running : le processus est mort en cours d'appel, l'écriture a pu aboutir
SELECT id, kind, path, created_at FROM passorde_requests WHERE status = 'running';
```

La table grandit sans limite : à purger selon vos besoins, par exemple au-delà de quelques mois.

## Lire `passorde_runs`

Une ligne par exécution de synchronisation, et une par mois civil pour une longue période de bons de livraison.

| Colonne | Sens |
| --- | --- |
| `kind` | `paytypes`, `clients`, `articles`, `users` ou `deliveries` |
| `window_from`, `window_to` | La période, pour les bons |
| `status` | `queued`, `running`, `succeeded`, `failed` ou `skipped` |
| `started_at`, `finished_at` | Début et fin |
| `rows_seen`, `rows_changed` | Lignes lues et réellement modifiées |
| `error` | Le motif d'un échec |
| `host_run_id` | L'identifiant d'exécution donné par l'application à `run()`, s'il y en a un |

```sql
SELECT id, kind, status, window_from, window_to, rows_seen, rows_changed, error, started_at
FROM passorde_runs ORDER BY id DESC LIMIT 50;
```

| Ce qu'on voit | Sens |
| --- | --- |
| `skipped` | Une autre exécution du même type tenait le verrou : normal si elles se chevauchent, et ce n'est pas un échec |
| `running` depuis longtemps | Attente de limite de débit, ou processus mort : le verrou expire après `passorde-connect.sync.lock_seconds` et la suite marque la ligne `failed` |
| `failed` avec un motif de licence | Voir plus haut ; aucun appel n'est parti |
| `rows_changed` à 0 | Normal : rien n'a changé |
