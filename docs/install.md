# Intégration dans Passorde

Passorde Connect est un composant de l'application Passorde : il est installé avec elle et déjà présent après l'installation. Il ne contacte de lui-même aucun service tant que le connecteur n'est pas activé dans les réglages du serveur ou qu'aucune clé de licence n'est saisie dans les réglages d'administration de Passorde, et ne contacte le logiciel de facturation qu'avec une clé utilisable (valide ou en période de grâce). Personne n'a à l'ajouter à la main, et il n'est pas fait pour être installé seul dans une autre application. L'application Passorde est publiée : <https://github.com/marinailsapps/passorde>.

Cette page est une référence pour qui lit le code : elle décrit comment Passorde accueille le cœur commun à tous les connecteurs (chargement, configuration, migrations, clé de licence, page de licence, planification, tests). Ce ne sont pas des étapes à suivre. Dans ce qui suit, « l'application » est l'application qui accueille le composant, c'est-à-dire Passorde. Ce qui est propre à un connecteur (identifiants, vérification, synchronisation, planification) est dans sa documentation, dans son dossier sous [`connectors/`](../connectors).

## Ce dont le composant a besoin

PHP 8.3 ou supérieur avec `sodium`, Laravel 12, une base de données, une clé `APP_KEY` stable, et un cache partagé entre les processus (Redis, base de données, Memcached) dès qu'il y a plusieurs workers : les verrous d'écriture des connecteurs en dépendent. L'application les fournit.

## 1. Chargement

Le composant n'est pas publié sur Packagist : l'application le déclare comme dépôt Composer. Ses service providers sont découverts automatiquement : `Passorde\PassordeServiceProvider` (le cœur), puis celui de chaque connecteur, dans son dossier sous `connectors/`. Un connecteur n'appelle son service qu'avec une licence qui le couvre et ses identifiants renseignés.

Quand le composant est pris dans un dossier local (dépôt Composer de type `path`, par exemple pendant un développement conjoint) : selon la plateforme et l'option `symlink`, Composer copie le dossier dans `vendor/` au lieu d'y faire un lien, et tant que le `composer.json` du composant ne change pas, `composer update` garde cette copie périmée. Pour reprendre le code du dossier :

```bash
rm -rf vendor/marinailsapps/passorde-connect && composer install
```

## 2. Configuration

Les réglages du cœur sont dans `config/passorde-connect.php`, lus sous la clé `passorde-connect` (par exemple `config('passorde-connect.writes_enabled')`). L'application peut en publier une copie (tag `passorde-connect-config`) pour fixer des valeurs ; sans copie, le composant utilise ses valeurs par défaut et les variables d'environnement de la section 5. Le nom `config/passorde.php` reste à l'application.

`passorde-connect.enabled` (activé par défaut, sans variable d'environnement) est l'interrupteur que Passorde règle d'après le sien, celui qui active le connecteur dans les réglages du serveur. Désactivé, le composant n'enregistre aucune commande `passorde:*`, aucune route (ni page de licence ni feuille de style) et aucune tâche planifiée : rien ne parle de connecteur ni de licence, `php artisan list` compris. Ses services restent disponibles pour le code de l'application ; ses tags de publication, ses vues et ses traductions restent enregistrés, mais rien ne les affiche de lui-même.

## 3. Migrations

Le cœur crée `passorde_requests` (journal des requêtes des connecteurs) et `passorde_licences` (la clé de licence, chiffrée) ; chaque connecteur ajoute les siennes, toutes préfixées `passorde_`. Elles sont publiées dans l'application sous le tag `passorde-migrations`, puis exécutées avec ses propres migrations ; `passorde_licences` ne sert qu'au stockage `database` du cœur, que Passorde n'utilise pas (voir « Où la clé est conservée ») :

```bash
php artisan vendor:publish --tag=passorde-migrations
```

Elles sont publiées sous leur propre nom (`2026_01_01_00000N_…`), même quand `database.migrations.update_date_on_publish` est activé, et ont lieu dans l'ordre de leur numéro. Après une mise à jour du composant, la même publication ne copie que les migrations nouvelles : une migration que l'application possède déjà, sous son nom ou sous une autre date, est laissée de côté, `--force` compris.

## 4. Clé de licence

La licence est une **clé d'une seule ligne**, reçue par e-mail, qui commence par `pck1.`. Elle porte la licence signée et le jeton qui sert à la rafraîchir : la traiter comme un mot de passe. Son format est décrit dans [licence-key.md](licence-key.md).

Dans Passorde, l'administrateur la colle dans les réglages d'administration, et Passorde la conserve avec ses réglages par sa propre implémentation de `LicenceStore` (voir « Où la clé est conservée »). Le cœur offre en plus une commande et une page de licence, décrites ci-dessous.

La commande `php artisan passorde:licence install` demande la clé sans l'afficher et sans passer par l'historique du shell ; `php artisan passorde:licence install /chemin/vers/cle.txt` la lit dans un fichier. La page `/{préfixe}/licence` fait la même chose (coller ou téléverser). Les espaces et retours à la ligne d'une clé coupée par un client de messagerie sont ignorés. `php artisan passorde:licence show` affiche le résultat, connecteurs couverts compris. Une clé qui ne couvre pas le connecteur en place est refusée, avec le motif. Sans licence valide, aucun appel ne part : voir la [FAQ licence](licence-faq.md).

Une licence au format JSON n'est pas acceptée : elle est refusée avec un motif qui le dit. Il faut la clé.

### Où la clé est conservée

Le cœur lit et écrit la clé par le contrat `Passorde\Licence\Contracts\LicenceStore` : `current()`, `previous()`, `replace(string $key)`, `lastRefreshAt()`, `markRefreshed()`. Il y écrit la clé à l'installation et à chaque rafraîchissement ; c'est au stockage de la chiffrer. Passorde lie sa propre implémentation, qui garde la clé avec ses réglages d'administration.

Sans implémentation liée par l'application, le cœur en fournit deux, choisies par `PASSORDE_LICENCE_STORE` :

| `PASSORDE_LICENCE_STORE` | Stockage |
| --- | --- |
| `database` (par défaut) | Table `passorde_licences`, chiffrée avec `APP_KEY` par le chiffreur de Laravel (`APP_PREVIOUS_KEYS` compris). La clé précédente y est gardée aussi. Une sauvegarde de la base ne contient la clé que chiffrée ; si `APP_KEY` est perdue ou changée, la page de licence le dit et il suffit de réinstaller la clé. `PASSORDE_LICENCE_CONNECTION` choisit une connexion autre que celle par défaut. |
| `file` | Une ligne dans `PASSORDE_LICENCE_PATH` (par défaut `storage/passorde/licence.key`), mode 600, la précédente à côté (`licence.previous.key`). À ne pas versionner. |

### Page de licence : qui peut y accéder

**Par défaut (`web,auth`), tout utilisateur connecté de l'application peut installer une licence, la remplacer ou lancer un rafraîchissement.** `PASSORDE_ROUTE_MIDDLEWARE` remplace cette liste ; Passorde y ajoute son propre middleware qui réserve la page aux administrateurs. `web` et `auth` doivent y rester. Les mêmes middlewares protègent la feuille de style de la page.

`PASSORDE_ROUTE_ENABLED=false` n'enregistre aucune route du composant : la clé ne s'installe plus alors que par l'application ou par `php artisan passorde:licence install`.

### Page de licence : lien de retour

La page porte en haut un lien « Retour » vers `PASSORDE_ROUTE_BACK_URL`, par défaut `/`, la racine de l'application ; il peut pointer vers la page d'administration d'où l'on vient.

### Page de licence et politique de sécurité du contenu

La page n'a ni `<style>` en ligne, ni attribut `style=`, ni script : elle fonctionne sous `style-src 'self'`. Sa feuille de style est servie par la route `GET /{préfixe}/licence.css` (nom `passorde.licence.css`), derrière les mêmes middlewares. Publiée sous le tag `passorde-assets`, elle est copiée dans `public/vendor/passorde/licence.css`, et la page pointe vers cette copie tant qu'elle existe ; la copie est à republier après une mise à jour du composant.

### Pas de licence de développement

Aucun réglage, aucune variable d'environnement et aucune commande n'ajoute une clé de signature reconnue, quel que soit `APP_ENV` : les clés reconnues sont fixées dans le code (`Passorde\Licence\PublicKeys`). En faire reconnaître une autre demande de modifier du code : changer `PublicKeys`, ou faire lier par l'application un autre vérificateur, comme le fait `TestLicence` dans les tests. La licence l'interdit (voir [LICENSE.md](../LICENSE.md), article 3). `TestLicence` ne fait pas partie du code chargé du composant : l'application doit l'ajouter à son `autoload-dev`, il ne fonctionne que si l'environnement est exactement `testing`, et il n'agit que si un test l'appelle. Voir plus bas, « Tests de l'application ».

## 5. Variables d'environnement

Toutes sont listées dans `.env.example`. Celles d'un connecteur sont dans sa documentation, sous `connectors/`.

| Variable | Par défaut | Rôle |
| --- | --- | --- |
| `PASSORDE_WRITES_ENABLED` | désactivé | Autorise les écritures des connecteurs |
| `PASSORDE_CACHE_STORE` | cache de l'application | Jetons et verrous des connecteurs |
| `PASSORDE_REQUEST_LOG_DRIVER` | `database` | `database` ou `null` ; Passorde peut aussi lier son propre `Passorde\Api\Contracts\RequestLog` |
| `PASSORDE_SCHEDULE_ENABLED` | désactivé | Synchronisation planifiée des connecteurs par le composant ; Passorde planifie lui-même la sienne et le laisse désactivé |
| `PASSORDE_SCHEDULE_TIMEZONE` | fuseau de l'application | Fuseau de ces horaires |
| `PASSORDE_LICENCE_STORE` | `database` | `database` ou `file`, sans stockage lié par l'application |
| `PASSORDE_LICENCE_PATH` | `storage/passorde/licence.key` | Fichier de la clé, avec le stockage `file` |
| `PASSORDE_LICENCE_CONNECTION` | connexion par défaut | Connexion de la table `passorde_licences` |
| `PASSORDE_LICENCE_REFRESH_URL` | le service de licences | Remplace l'adresse de rafraîchissement, pour un service de test seulement ; vide, celle du composant (`Passorde\Licence\LicenceService::REFRESH_URL`) |
| `PASSORDE_LICENCE_REFRESH_ENABLED` | activé | Rafraîchissement quotidien planifié par le composant ; sans effet dans Passorde, qui le laisse désactivé et planifie lui-même le rafraîchissement, sans réglage pour l'arrêter |
| `PASSORDE_ROUTE_ENABLED` | activé | `false` : aucune route du composant, ni page de licence ni feuille de style |
| `PASSORDE_ROUTE_PREFIX` | `passorde` | Préfixe de la page de licence |
| `PASSORDE_ROUTE_BACK_URL` | `/` | Destination du lien « Retour » de la page de licence : un chemin ou une adresse complète de l'application |
| `PASSORDE_ROUTE_MIDDLEWARE` | `web,auth` | Middlewares de cette page ; par défaut, tout utilisateur connecté y installe une licence (voir plus haut) |

## 6. Planificateur

Le rafraîchissement quotidien de la licence passe par le planificateur de Laravel : sans lui, aucune clé prolongée n'arrive d'elle-même, et il faut la coller à la main. `PASSORDE_LICENCE_REFRESH_ENABLED=false` retire l'entrée planifiée par le composant ; Passorde la retire et planifie lui-même `passorde:licence refresh`, tant que le connecteur est activé, sans réglage pour l'arrêter. Avec une licence sans jeton ou sans licence installée, il n'y a rien à rafraîchir (une licence à vie qui porte un jeton est rafraîchie : c'est ainsi qu'une prolongation de ses mises à jour arrive) : la tâche se termine sans erreur ni avertissement (code 0, une ligne « Rien à rafraîchir »). `LicenceRefresher::isRefreshable(app(LicenceManager::class)->status())` dit s'il y a quelque chose à rafraîchir, pour que Passorde, qui planifie lui-même la tâche, puisse s'en abstenir.

Le planificateur tourne quand le cron du serveur le lance, par exemple :

```cron
* * * * * cd /chemin/vers/application && php artisan schedule:run >> /dev/null 2>&1
```

## Tests de l'application

Le garde de licence s'applique aussi dans les tests de l'application. `Passorde\Testing\TestLicence` installe une licence signée par une paire de clés créée dans le processus de test et jamais enregistrée. **Il n'est pas chargé avec le composant** : son dossier `testing/` est présent dans toute installation, production comprise (Composer installe les fichiers du composant en entier), mais il est hors du code chargé automatiquement. La classe ne se charge que si l'application ajoute ce dossier à son propre `autoload-dev`, que `composer install --no-dev` ignore ; Passorde le fait ainsi :

```json
{
    "autoload-dev": {
        "psr-4": {
            "Passorde\\Testing\\": "vendor/marinailsapps/passorde-connect/testing/"
        }
    }
}
```

Il ne fonctionne que si l'environnement de l'application est exactement `testing`, celui que fixe le lanceur de tests de Laravel : en `local`, `staging`, `production` ou tout autre, il lève une exception avant de reconnaître quoi que ce soit.

```php
use Passorde\Licence\LicenceState;
use Passorde\Testing\TestLicence;

protected function setUp(): void
{
    parent::setUp();

    TestLicence::install();                         // licence à vie, valide
}

protected function tearDown(): void
{
    TestLicence::reset();

    parent::tearDown();
}
```

Avec Pest : `beforeEach(fn () => TestLicence::install());`, après le démarrage de l'application.

| Appel | Effet |
| --- | --- |
| `install(LicenceState $state = LicenceState::Valid, array $overrides = [])` | Fait confiance à la clé de test (kid `test`) et enregistre une clé par le stockage lié : `Valid` à vie, `Grace` expirée il y a 3 jours, `Expired` il y a 30 jours, `Invalid` avec une signature fausse. Elle couvre tous les connecteurs enregistrés. Les dates suivent l'heure courante, celle de `Carbon::setTestNow()` comprise. `$overrides` remplace des champs avant la signature, par exemple `['expires_at' => '2026-12-31']` ou `['connectors' => ['autre']]`. |
| `missing()` | Retire la clé : l'état devient « invalide, non installée ». |
| `reset()` | Supprime les fichiers écrits et rend au conteneur le vérificateur des vraies clés. |
| `signed(LicenceState $state = LicenceState::Valid, array $overrides = [])` | La clé (`pck1.…`), pour l'installer autrement, par la page de licence par exemple. |
| `publicKey()` | La clé publique de test, en base64. |

Avec le stockage `database`, la table `passorde_licences` doit exister dans la base de test (migrations publiées et exécutées par `RefreshDatabase`), ou les tests passent au stockage `file` (`PASSORDE_LICENCE_STORE=file` dans `phpunit.xml`). Avec le stockage `file`, si l'application n'a pas changé `passorde-connect.licence.path` et que ses tests tournent avec `APP_ENV=testing`, `install()`, `missing()` et `reset()` font d'abord pointer ce réglage vers un fichier temporaire propre au processus (`TestLicence::temporaryPath()`) : les tests n'écrasent jamais la clé de la pile de développement.

Tant qu'elle est installée, seule la clé de test est reconnue : une vraie licence ne passe plus. Le gestionnaire et le rafraîchisseur déjà résolus sont reconstruits ; le garde d'un client de connecteur déjà résolu suit, car il relit le gestionnaire à chaque vérification. À n'appeler que dans des tests.
