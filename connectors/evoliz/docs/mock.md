# Simulateur Evoliz

_Passorde Connect — Evoliz, compatible avec Evoliz, non affilié à Evoliz SAS / Visma._

`connectors/evoliz/mock` imite l'API d'Evoliz sur des données entièrement fictives. Il sert à essayer une installation, à répéter les pannes et à faire tourner les tests de bout en bout. Il ne demande que PHP.

## Démarrer

Depuis Passorde, où le composant se trouve sous `vendor/` :

```bash
php -S 127.0.0.1:8090 vendor/marinailsapps/passorde-connect/connectors/evoliz/mock/index.php
```

Depuis ce dépôt :

```bash
php -S 127.0.0.1:8090 connectors/evoliz/mock/index.php
```

Il écoute sur la machine locale et n'a aucune authentification sur `/__mock/` : ne pas l'exposer sur un réseau. L'état tient dans un fichier du dossier temporaire et survit à un redémarrage jusqu'au prochain `reset`.

## Y brancher le connecteur

```dotenv
PASSORDE_EVOLIZ_BASE_URL=http://127.0.0.1:8090
PASSORDE_EVOLIZ_COMPANY_ID=1000
PASSORDE_EVOLIZ_PUBLIC_KEY=mock-public-key
PASSORDE_EVOLIZ_SECRET_KEY=mock-secret-value
PASSORDE_WRITES_ENABLED=true
```

Puis `php artisan config:clear`. Le simulateur n'accepte que ces clés et ne conserve jamais le corps d'une connexion : une vraie clé envoyée par erreur est refusée et oubliée.

**Une licence reste nécessaire.** Le garde ne fait aucune exception pour le simulateur : sans fichier valide, `passorde:ping` s'arrête avant d'envoyer quoi que ce soit.

```bash
php artisan passorde:ping
```

```bash
php artisan passorde:sync all
```

```bash
php artisan passorde:sync deliveries --days=200
```

Par défaut, `passorde:sync deliveries` ne regarde que 7 jours en arrière et 31 en avant : il voit donc 2 bons sur les 24.

**Avant de passer aux vraies clés, vider les tables `passorde_*` et celles des destinations de Passorde.** Les lignes fictives portent des `evoliz_id` qui n'ont aucun sens sur un compte réel et pourraient y coïncider avec de vrais identifiants.

## Les données

Elles sont construites à partir de la date du jour, sans hasard : la même date donne les mêmes données, et les bons tombent toujours autour d'elle. `PASSORDE_MOCK_TODAY=2026-06-15` fixe cette date, `PASSORDE_MOCK_STATE` le fichier d'état.

| Donnée | Quantité | Particularités |
| --- | --- | --- |
| Clients | 12, à partir de 5001 | 5011 désactivé |
| Contacts | 12 | 5001 en a deux, 5012 aucun |
| Articles | 36, à partir de 3001 | Un sur trois sans gestion de stock ; 3004 en stock négatif ; 3035 désactivé ; 3036 avec `&` dans sa référence |
| Bons de livraison | 24, sur environ six mois | Un sur deux sans référence externe ; quelques-uns facturés ; numéros `BL-MOCK-…` |
| Moyens de paiement | 2 | 41 Virement, 42 Chèque |
| Conditions de paiement | 2 | 31 À réception, 37 30 jours |
| Utilisateurs | 2 | 91 et 92 |
| Taux de TVA, classements | 3 et 2 | |

Les textes sont stockés encodés en HTML, comme chez Evoliz.

## Ce qu'il répond

- `POST /api/login` : un jeton valable 20 minutes.
- Pagination `per_page` (1 à 100, 15 par défaut) et `page`, avec `links` et `meta`.
- `X-RateLimit-Limit` et `X-RateLimit-Remaining` : 100 appels par minute réelle, puis 429 avec `Retry-After`. Sous 10 appels restants, le connecteur fait une vraie pause de 60 s.
- `GET` : `/clients` (`code`), `/contacts-clients` (`clientid`), `/articles` (`reference`), `/paytypes`, `/payterms`, `/users`, `/vat-rates`, `/sale-classifications`, `/deliveries` (`clientid`, `period=custom` avec `date_min` et `date_max`, `period=currentyear`, lignes dans `items`), et chaque ressource par son identifiant.
- `POST /clients` (`vat_number` exigé), `PATCH /clients/{id}`.
- `POST` et `PATCH /contacts-clients`.
- `POST /articles`, `PATCH /articles/{id}` pour `stocked_quantity`, qui écrase la quantité.
- `POST /deliveries` : enregistré aussitôt, numéroté `BL-MOCK-…`, avec `external_document_number` conservé et retrouvable. Le stock des articles suivis diminue.
- `search` sur `/deliveries` : 400 `Unexpected filter`, comme Evoliz.

Le connecteur ne doit jamais appeler les chemins suivants. Le simulateur les refuse et les note dans `forbidden_hits` :

| Chemin | Réponse |
| --- | --- |
| Tout chemin contenant `invoices`, `invoice`, `progress-invoice`, `credits` ou `recoveries`, toute méthode | 403 |
| `DELETE /deliveries/{id}` | 403 `Only draft deliveries can be deleted` |
| `PATCH`, `PUT`, `POST /deliveries/{id}` et tout ce qui est sous `/deliveries/{id}/` | 405, sans en-tête de limite |

## Le piloter

```bash
curl -X POST http://127.0.0.1:8090/__mock/reset
```

```bash
curl http://127.0.0.1:8090/__mock/state
```

`state` donne la date, les interrupteurs, le nombre d'objets, les derniers appels (méthode, chemin, paramètres, statut) et `forbidden_hits`, qui doit rester vide. `reset` rétablit tout, jetons compris : le connecteur se reconnecte tout seul.

```bash
curl -X POST http://127.0.0.1:8090/__mock/switches -H 'Content-Type: application/json' -d '{"read_only": true}'
```

| Interrupteur | Effet | Ce qu'on doit voir côté connecteur |
| --- | --- | --- |
| `read_only` | Toute écriture répond 403 `This action is unauthorized.` | `Refused` avec le statut 403 ; les lectures continuent |
| `client_patch_500` | `PATCH /clients/{id}` enregistre puis répond 500 | `Updated` avec `afterUncertainResponse` |
| `delivery_post_500` | `POST /deliveries` enregistre puis répond 500 | `AlreadyExists` avec `afterUncertainResponse`, un seul bon |
| `rate_limit_remaining` | Impose cette valeur ; 0 répond 429 ; `null` revient au décompte | Pause et avertissement, ou échec du 429 |

## Les tests

```bash
vendor/bin/pest --group=mock
```

Ils démarrent le simulateur en sous-processus sur un port libre et passent par le vrai client HTTP : synchronisation de tous les types, création de client, contact, article et bon, deux fois chacun, ajustement de stock, `passorde:status`, `passorde:ping`, puis les pannes ci-dessus.

## Ce qu'il ne prouve pas

Le simulateur reproduit ce qui a été observé et, ailleurs, fait un choix. Des tests verts montrent que le connecteur tient ses promesses **si l'API se comporte ainsi**, pas qu'elle le fait. Ses choix non vérifiés :

- un bon est listé dès sa création ;
- `external_document_number` est unique par client ;
- `clientid` se combine avec `period=custom`, sur une période de plus d'un an ;
- la forme du refus d'une clé en lecture seule vient de la documentation d'Evoliz, pas d'un essai ;
- les filtres `code` et `reference` cherchent un texte contenu, sans tenir compte de la casse : c'est le cas le moins favorable, qui oblige le connecteur à comparer exactement.

Voir les [comportements à confirmer sur un compte réel](api-to-confirm.md).
