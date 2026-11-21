# Changelog

## 1.0.0-beta.5 (2026-11-21)

- One clean history: the repository starts again from this version, as a single commit. The versions below are kept as release notes; their tags are no longer published.
- Licences are sold only to businesses established in France (COMMERCIAL). The Passorde application is published, under the MIT licence, at <https://github.com/marinailsapps/passorde> (README, « Intégration dans Passorde »).
- Examples, fixtures and the licence test vectors use a generic company and generic goods and name no real place; `tests/fixtures/licence-vectors.json` is written again with a new one-off key, so a signing service that checks itself against it takes the new file.
- The mock's data test checks every name, town and designation against the mock's own invented sets.
- Code comments and connector docs describe Passorde's own destinations and checks, the component's only host.

## 1.0.0-beta.4 (2026-11-15)

- `passorde-connect.enabled` (default on, set by the host, read from no environment variable): switched off, neither provider registers a command, a route or a scheduled task, so `artisan list`, the licence page and the schedule show nothing of the component; its services stay bound. Passorde sets it from its own connector switch, so that switched off it says nothing about licences.
- Documents: Passorde Connect is presented as what it is, the connectors component of the Passorde application, installed with it; it contacts no service by itself while the connector is not switched on in the server settings or no licence key is entered in Passorde's admin settings, and reaches the invoicing software only with a usable key (valid or in grace). README, COMMERCIAL, `composer.json`'s description and the licence page subtitle say so; `docs/install.md` becomes « Intégration dans Passorde », a reference for readers of the code on how Passorde hosts the component, instead of an install guide for any Laravel application. Sales are not open yet.
- `LicenceService::SITE_URL`, the address the grace, expired and updates-ended messages give, is now the Passorde page, `https://apps.marinails.site/passorde`, whose connectors section holds the prices; `composer.json`'s homepage is the same. The former `/passorde-connect` page redirects there.
- `passorde:ping` checks the licence before any call, as a sync run does: without a usable key it ends with one line, « Refusé par la licence : … », and exit code 2, even when the host's own transport refuses every call in that state. Before, Passorde in standalone mode printed its transport's stack trace.

## 1.0.0-beta.3 (2026-11-14)

First public version of Passorde Connect.

### Package

- Composer name `marinailsapps/passorde-connect`, namespace `Passorde\`, PHP 8.3 and Laravel 12. A core shared by every connector, and one connector so far, Passorde Connect — Evoliz (compatible avec Evoliz, non affilié à Evoliz SAS / Visma), a module in `connectors/evoliz` (`src`, `config`, `database/migrations`, `mock`, `docs`, `tests`).
- Two auto-discovered providers: `Passorde\PassordeServiceProvider` (licence, request log, licence page, `passorde:licence`) and the connector's own (client, sinks, sync, schedule, `passorde:ping|sync|status`, its migrations). `Passorde\Connectors\ConnectorRegistry` lists the connectors, their ids, product names and notices.
- Configuration `config/passorde-connect.php` (published with `--tag=passorde-connect-config`); the connector's settings in `connectors/evoliz/config/evoliz.php`, merged under `passorde-connect.evoliz`, its variables in `connectors/evoliz/.env.example`.

### Licence

- A one-line key, `pck1.` + base64url of the signed JSON (`docs/licence-key.md`), product `passorde-connect`, a signed `connectors` list. Ed25519, verified offline against `Passorde\Licence\PublicKeys`, which holds one production key, kid `2026-11`. No setting, variable or command adds another.
- Stored in the database (table `passorde_licences`, encrypted with `APP_KEY`) or in a file; a host may bind its own store.
- Checked before every call to the connected service: in the client, in each write and in the token; a connector the key does not list is refused (`connector_not_covered`, with the connector's product name and notice).
- Grace: the warning starts when the paid period ends (00:00 Europe/Paris after `expires_at`); synchronisation stops 14 calendar days later, at 00:00 Paris.
- Updates for 12 months on a lifetime licence: a signed `updates_until` covers the versions released on or before that day (`Passorde::RELEASED_AT`, set at each release with this heading); a later version refuses the key (`updates_ended`), an earlier one keeps it valid without end date. A key without `updates_until` has no such limit.
- Daily refresh from the licence service (`Passorde\Licence\LicenceService::REFRESH_URL`), lifetime keys with a token included; a refresh never replaces a key with one that ends earlier, stops updates earlier or drops a connector in use.
- `Passorde\Testing\TestLicence` for a host's own tests, in `testing/` (outside the autoloaded code), working only when the environment is exactly `testing`.

### Documents

- LICENSE: reading and trying allowed, real use needs a paid licence, removing the check or reselling forbidden; one licence for one company. COMMERCIAL: plans and prices. Docs: installation, licence FAQ, key format, and the connector's own documentation in `connectors/evoliz/docs`.
