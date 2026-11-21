# Passorde Connect — Evoliz

Le premier connecteur de Passorde Connect, le composant de connecteurs de Passorde. Il relie Passorde à un compte Evoliz, qu'il lit et où il écrit : clients et contacts, articles et stock, moyens de paiement, utilisateurs et bons de livraison, avec un miroir local en base. Compatible avec Evoliz, non affilié à Evoliz SAS / Visma.

**Bons de livraison uniquement, jamais de factures.** Le connecteur crée des bons de livraison et rien d'autre sur eux : il ne facture pas, ne modifie pas, ne supprime pas et n'envoie pas de bon. Les chemins de facturation sont bloqués dans le code et aucune configuration ne les rouvre.

Il est installé avec Passorde et reste silencieux tant que le connecteur n'est pas activé dans les réglages du serveur ou qu'aucune clé de licence valide n'est saisie dans les réglages d'administration de Passorde. Il appelle Evoliz seulement avec une clé de licence Passorde Connect utilisable (valide ou en période de grâce) qui le couvre (`evoliz` dans la liste `connectors` de la clé ; produit nommé « Passorde Connect — Evoliz », dont les licences seront vendues sur la page Passorde, <https://apps.marinails.site/passorde#tarifs> ; la vente n'est pas encore ouverte). Comment le cœur (clé, page de licence) se branche dans l'application : [intégration dans Passorde](../../docs/install.md).

## Fonctions

- **Lecture** : clients et contacts, articles et stock, moyens de paiement, utilisateurs, bons de livraison avec leurs lignes, conditions de paiement, taux de TVA, classements.
- **Miroir** : tables `passorde_*` prêtes à l'emploi, ou les destinations de Passorde, par contrat.
- **Écriture**, désactivée par défaut : clients, contacts, articles, ajustement de stock, bons de livraison. Résultats typés, journal avec secrets masqués.
- **Pas de doublon de bon** : la référence externe est cherchée avant toute création et avant toute reprise, sous verrou.
- **Exploitation** : limite de débit, reprises, jeton en cache, planification facultative, `passorde:ping`, `passorde:sync`, `passorde:status`.
- **Simulateur** d'Evoliz sur données fictives, sans dépendance.

## Dans ce dossier

| Dossier | Contenu |
| --- | --- |
| `src/` | Classes du connecteur, sous l'espace de noms `Passorde\` (`Passorde\Api`, `Passorde\Reads`, `Passorde\Writes`, …) ; son provider `Passorde\Evoliz\EvolizServiceProvider` |
| `database/migrations/` | `passorde_runs` et les tables du miroir |
| `mock/` | Le simulateur |
| `docs/` | Documentation détaillée |
| `tests/` | Ses tests, avec les réponses d'Evoliz en `tests/fixtures` |

## Tables

Les migrations publiées par `--tag=passorde-migrations` créent, en plus de celles du cœur, `passorde_runs` et les tables du miroir (voir [architecture](docs/architecture.md)). `passorde_runs` est requise même quand Passorde lie ses propres destinations. `passorde_requests` (cœur) ne journalise que les appels des verbes d'écriture, y compris les lectures qu'ils font pour eux-mêmes (recherche avant création, relecture, lecture du stock). Les lecteurs, `passorde:sync` et les appels directs à `EvolizApi` n'y écrivent rien : c'est voulu, pour ne pas remplir la table à chaque synchronisation ; une exécution se suit dans `passorde_runs`. La migration `add_host_run_id_to_passorde_runs_table` n'est nécessaire que si Passorde passe son propre identifiant d'exécution (voir [architecture](docs/architecture.md#exécutions)), mais elle est sans risque pour les autres.

## Variables d'environnement

Dans Passorde, l'administrateur saisit la clé de licence et les identifiants Evoliz dans les réglages d'administration ; les variables ci-dessous sont celles du composant lui-même.

Les réglages du connecteur sont dans [`config/evoliz.php`](config/evoliz.php), dans ce dossier ; son provider les fusionne sous `passorde-connect.evoliz` (l'application lit par exemple `config('passorde-connect.evoliz.company_id')`). Ses variables sont listées dans [`.env.example`](.env.example), ici ; les variables communes (écritures, cache, planification) sont celles du cœur ([intégration](../../docs/install.md#5-variables-denvironnement)). Pour changer un réglage sans variable (`max_pages`, `rate_limit`), l'application ajoute un tableau `evoliz` à son `config/passorde-connect.php` publié : ses clés l'emportent, une par une, sur celles du connecteur.

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `PASSORDE_EVOLIZ_BASE_URL` | — | Adresse de l'API, sans `/api` |
| `PASSORDE_EVOLIZ_COMPANY_ID` | — | Identifiant de la société |
| `PASSORDE_EVOLIZ_PUBLIC_KEY`, `PASSORDE_EVOLIZ_SECRET_KEY` | — | Clés d'API |
| `PASSORDE_EVOLIZ_TIMEOUT`, `PASSORDE_EVOLIZ_CONNECT_TIMEOUT` | 30, 10 | En secondes |
| `PASSORDE_EVOLIZ_FORBIDDEN_PATHS` | les chemins de facturation | Ajoute des chemins interdits ; ne peut en retirer aucun |
| `PASSORDE_WRITES_ENABLED` | désactivé | Autorise les écritures vers Evoliz |
| `PASSORDE_WRITES_MIRROR` | activé | `false` : un verbe ne remet plus l'enregistrement écrit aux destinations et ne relit plus le client ni ses contacts pour elles ; voir [écritures](docs/writes.md#destinations) |
| `PASSORDE_CACHE_STORE` | cache de l'application | Jeton, verrous de synchronisation et d'écriture |
| `PASSORDE_SCHEDULE_ENABLED` | désactivé | Synchronisation planifiée |

Pour une première mise en route, laisser `PASSORDE_WRITES_ENABLED` désactivé, puis l'activer une fois les lectures vérifiées.

## Vérifier

```bash
php artisan passorde:ping
```

Il se connecte, fait une lecture et affiche la société, les appels restants et l'âge du jeton. Pour essayer sans compte Evoliz, voir le [simulateur](docs/mock.md) : il demande lui aussi une clé de licence qui couvre Evoliz, car le garde de licence s'applique à tout appel ; les tests automatisés utilisent `TestLicence` ([intégration](../../docs/install.md#tests-de-lapplication)).

## Synchroniser

```bash
php artisan passorde:sync all
```

Le type est `clients`, `articles`, `paytypes`, `deliveries`, `users` ou `all`. Pour les bons de livraison, `--since=AAAA-MM-JJ`, `--until=AAAA-MM-JJ` et `--days=N` choisissent la période. `php artisan passorde:status` affiche la dernière exécution de chaque type et l'état de la licence pour ce connecteur.

Les appels peuvent attendre jusqu'à 60 s en cas de limite de débit : à lancer en ligne de commande ou en file d'attente, pas dans une requête web.

## Planification

La synchronisation planifiée est désactivée par défaut ; `PASSORDE_SCHEDULE_ENABLED=true` l'active (planificateur de Laravel requis, voir [intégration](../../docs/install.md#6-planificateur)) :

| Type | Fréquence |
| --- | --- |
| Articles | Toutes les 15 min du lundi au vendredi de 8 h à 19 h, toutes les heures sinon |
| Bons de livraison, 7 derniers jours et 31 prochains | Toutes les 15 min |
| Clients | Chaque nuit à 2 h 07 |
| Moyens de paiement | Chaque nuit à 2 h 22 |
| Bons de livraison, 60 derniers jours et 31 prochains | Chaque nuit à 2 h 37 |

Les heures ouvrées et l'expiration du verrou de chevauchement (30 min) se règlent dans `config/passorde-connect.php`. Les utilisateurs ne sont pas planifiés : `php artisan passorde:sync users` suffit, à la demande.

## Documentation

- [Architecture](docs/architecture.md)
- [Écritures](docs/writes.md)
- [Dépannage](docs/troubleshooting.md)
- [Simulateur](docs/mock.md)
- [Comportements à confirmer sur un compte réel](docs/api-to-confirm.md)

## Avant la production

Avant de passer aux vraies clés, lire les [comportements à confirmer sur un compte réel](docs/api-to-confirm.md). Un bon de livraison créé sur un compte réel est définitif : l'API ne le supprime pas.
