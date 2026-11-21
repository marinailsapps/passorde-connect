# Passorde Connect

Ce dépôt contient **Passorde Connect, le composant de connecteurs de l'application Passorde** : la partie de Passorde qui la relie à un logiciel de facturation. Il est installé avec Passorde, comme un composant ordinaire déjà présent après l'installation, et n'est pas fait pour être installé seul. Il ne contacte de lui-même aucun service tant que le connecteur n'est pas activé dans les réglages du serveur ou qu'aucune clé de licence n'est saisie dans les réglages d'administration de Passorde, et ne contacte le logiciel de facturation qu'avec une clé utilisable (valide ou en période de grâce). Le premier connecteur, et à ce jour le seul, est **Passorde Connect — Evoliz**, compatible avec Evoliz, non affilié à Evoliz SAS / Visma. Les licences seront vendues sur la page Passorde, <https://apps.marinails.site/passorde#tarifs>, dans la partie consacrée aux connecteurs ; la vente n'est pas encore ouverte (voir [COMMERCIAL.md](COMMERCIAL.md)).

L'application Passorde est publiée sous licence MIT : <https://github.com/marinailsapps/passorde>.

Ce composant a son propre dépôt pour une seule raison : sa licence n'est pas celle de Passorde. Passorde est sous licence MIT ; Passorde Connect est sous une licence propriétaire à code consultable (voir [Licence](#licence)).

Version actuelle : `1.0.0-beta.5`.

## Silencieux sans licence

Dans le code de ce dépôt :

- aucun connecteur ne contacte son service sans une clé de licence utilisable (valide ou en période de grâce) qui le nomme : le garde de licence (`Passorde\Licence\LicenceGuard`) est appelé avant toute requête, par le client, par chaque exécution de synchronisation et par chaque verbe d'écriture ;
- la synchronisation planifiée (`PASSORDE_SCHEDULE_ENABLED`) et les écritures (`PASSORDE_WRITES_ENABLED`) sont désactivées par défaut ;
- désactivé par l'application qui l'accueille (`passorde-connect.enabled`), le composant n'enregistre aucune commande, aucune page et aucune tâche planifiée.

L'interrupteur du serveur qui active un connecteur et le bloc de réglages où l'administrateur saisit la clé appartiennent à Passorde, pas à ce dépôt. Quand le connecteur y est désactivé, Passorde désactive aussi le composant.

## Connecteurs

| Connecteur | Dossier | Documentation |
| --- | --- | --- |
| **Passorde Connect — Evoliz** (compatible avec Evoliz, non affilié à Evoliz SAS / Visma) : clients, catalogue et stock lus, commandes approuvées transmises comme bons de livraison, corrections de stock écrites ; la facturation reste dans le logiciel de facturation. | [`connectors/evoliz`](connectors/evoliz) | [connectors/evoliz/README.md](connectors/evoliz/README.md) |

## Ce que le cœur apporte à tous les connecteurs

- **Licence** vérifiée hors ligne (signature Ed25519, clé publique incluse dans le code), sans service tiers entre Passorde et le service connecté ; rafraîchissement quotidien de la clé, quand le planificateur de tâches tourne.
- **Clé de licence** d'une seule ligne, conservée par le stockage que l'application lie (dans Passorde, avec ses réglages) ; à défaut, chiffrée en base (`passorde_licences`, avec la clé `APP_KEY`) ou dans un fichier.
- **Journal des requêtes** (`passorde_requests`), secrets masqués.
- **Page de licence** (`/passorde/licence`) et commande `passorde:licence`, sans script ni style en ligne.

## Contenu du dépôt

| Dossier | Contenu |
| --- | --- |
| `src/` | Le cœur, sous l'espace de noms `Passorde\` : licence, journal des requêtes, page de licence, registre des connecteurs |
| `connectors/evoliz/` | Le connecteur Passorde Connect — Evoliz : son code, sa configuration, ses migrations, son simulateur, sa documentation et ses tests |
| `config/`, `database/`, `lang/`, `resources/`, `routes/` | Configuration, migrations, textes français et page de licence du cœur |
| `testing/` | `TestLicence`, pour les tests de l'application qui accueille le composant ; hors du code chargé automatiquement |
| `tests/` | Tests du cœur, dont les vecteurs de licence (`tests/fixtures`) |
| `docs/` | Intégration dans Passorde, FAQ licence, format de la clé |

## Lire et tester le code

Pour travailler sur le dépôt lui-même : PHP 8.3 ou supérieur avec `sodium`, et Composer. Les tests tournent sur une application Laravel de test (Orchestra Testbench), avec une base SQLite en mémoire et sans service extérieur ; ils installent leur propre licence de test.

```bash
composer install
```

```bash
composer check
```

`composer check` lance les mêmes contrôles que l'intégration continue : `pint --test`, `phpstan` et `pest`. Le connecteur Passorde Connect — Evoliz a un [simulateur](connectors/evoliz/docs/mock.md) sur données fictives, qui ne demande que PHP ; les tests de bout en bout s'en servent. Hors des tests, le garde de licence s'applique aussi aux appels vers le simulateur : il faut une clé de licence valide.

La manière dont Passorde accueille le composant (providers, configuration, migrations, stockage de la clé, page de licence, tests de l'application) est décrite pour qui lit le code dans [docs/install.md](docs/install.md). Ce n'est pas un guide pour l'installer dans une autre application.

## Documentation

- [Intégration dans Passorde](docs/install.md)
- [FAQ licence](docs/licence-faq.md)
- [Format de la clé de licence](docs/licence-key.md)
- [Passorde Connect — Evoliz](connectors/evoliz/README.md) (compatible avec Evoliz, non affilié à Evoliz SAS / Visma)
- [Historique des versions](CHANGELOG.md)

## Licence

Le code source est consultable par tous, mais Passorde Connect n'est pas un logiciel open source : le lire et l'essayer est permis ; l'utiliser réellement requiert une licence payante ; supprimer la vérification de licence ou revendre le logiciel est interdit. Passorde Connect est publié et distribué sous licence par Maryna SYOMKO, entrepreneur individuel. Voir [LICENSE.md](LICENSE.md), [COMMERCIAL.md](COMMERCIAL.md) et [THIRD-PARTY.md](THIRD-PARTY.md).
