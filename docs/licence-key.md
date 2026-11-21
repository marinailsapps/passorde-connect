# Format de la clé de licence

La référence pour qui émet les clés (le service de vente) et pour qui les lit (ce paquet). Les vecteurs de test sont dans [`tests/fixtures/licence-vectors.json`](../tests/fixtures/licence-vectors.json).

## La clé

```text
pck1.<base64url sans remplissage du JSON de la licence, signature comprise>
```

- `pck1.` : préfixe et version du format. Un lecteur refuse `pckN.` pour tout autre `N` (motif `unsupported_key_version`) ; la casse du préfixe est indifférente.
- Corps : alphabet base64url (`A-Z a-z 0-9 - _`), sans `=`. Une longueur dont le reste modulo 4 vaut 1 est invalide.
- Le JSON décodé est un objet UTF-8 : les champs de la licence et `signature`. L'émetteur l'écrit sous forme canonique (clés triées par point de code, à tous les niveaux, `signature` comprise, sans espace, barres obliques et caractères non ASCII non échappés) : une même licence donne toujours la même clé. Un lecteur accepte tout objet JSON valide, seule la signature compte.
- Les espaces et retours à la ligne sont ignorés partout dans la clé. Longueur maximale lue : 16 384 caractères ; une clé réelle en fait environ 600.
- Un texte qui commence par `{` est une licence au format JSON : refusée (motif `legacy_format`).

## Les champs signés

| Champ | Type | Sens |
| --- | --- | --- |
| `kid` | texte | Identifiant de la clé de signature ; doit figurer dans `Passorde\Licence\PublicKeys::KEYS`, qui ne contient que la clé de production `2026-11` |
| `licence_id` | texte | Identifiant stable de la licence, conservé au renouvellement |
| `licence_token` | texte ou `null` | Jeton porteur du rafraîchissement. Le service de licences le met dans chaque clé qu'il émet, licence à vie comprise : c'est par lui qu'une prolongation des mises à jour arrive. Absent ou `null` : la clé n'est pas rafraîchie |
| `product` | texte | Toujours `passorde-connect` |
| `connectors` | liste de textes | Connecteurs couverts, au moins un, sans doublon, chacun en `[a-z][a-z0-9-]*` : l'identifiant que chaque connecteur déclare (`Passorde\Connectors\ConnectorRegistry`) |
| `plan` | texte | `3m`, `12m`, `lifetime`… |
| `email`, `company`, `siret` | texte ou `null` | Titulaire |
| `issued_at` | `AAAA-MM-JJ` | Date d'émission, heure de Paris |
| `expires_at` | `AAAA-MM-JJ` ou `null` | Dernier jour couvert ; `null` : sans expiration |
| `version_max` | `M.x` ou `M.m` | Dernière version du paquet couverte |
| `updates_until` | `AAAA-MM-JJ`, `null` ou absent | Dernier jour de mises à jour, heure de Paris : la licence couvre les versions dont `Passorde::RELEASED_AT` tombe ce jour-là ou avant (voir plus bas). Absent ou `null` : pas de limite |
| `sites` | nombre ou liste | Informatif |
| `signature` | base64 | Ed25519 détachée sur la forme canonique de tous les champs sauf `signature` |

Tout autre champ est accepté et signé avec le reste. Les nombres à virgule sont à éviter.

## Rafraîchissement

`GET {adresse}/licence/{licence_id}` avec `Authorization: Bearer {licence_token}` et `Accept: text/plain`, sans suivre de redirection, 10 s au plus. L'adresse est celle du service de licences, fixée dans `Passorde\Licence\LicenceService::REFRESH_URL` ; `PASSORDE_LICENCE_REFRESH_URL` la remplace seulement si elle est renseignée (service de test). Réponse attendue : `200`, corps = la clé à jour, une ligne (un saut de ligne final est toléré).

Le paquet ne remplace jamais une clé par une moins bonne. Il garde la clé en place, sauf si la clé reçue :

- se vérifie (signature, produit, champs ; son état n'est pas « invalide ») ;
- porte le même `licence_id` ;
- couvre encore chaque connecteur de l'installation que couvre la clé en place (sinon motif « ne couvre plus … ») ;
- ne se termine pas plus tôt : `expires_at` nul, ou égal ou postérieur à celui de la clé en place (sinon motif « se termine plus tôt ») ;
- n'arrête pas les mises à jour plus tôt : `updates_until` nul quand celui de la clé en place l'est, sinon nul, égal ou postérieur (sinon motif « arrête les mises à jour plus tôt »).

Une licence à vie qui porte un `licence_token` est rafraîchie comme un abonnement : c'est ainsi qu'une prolongation de ses mises à jour s'installe d'elle-même, quand le rafraîchissement planifié tourne. Seule une clé sans jeton n'est pas rafraîchie.

Tout autre statut ou corps laisse aussi la clé en place.

## Fin des mises à jour

`R` est la date de publication de la version installée (`Passorde\Passorde::RELEASED_AT`, fixée à chaque version, comme le titre du CHANGELOG), `U` est `updates_until`. Ce sont des jours du calendrier, sans heure : l'horloge n'intervient pas.

- `U` absent ou `null` : aucune limite.
- `R` ≤ `U` : la version est couverte ; une licence à vie reste valide sans date de fin.
- `R` > `U` : invalide, motif `updates_ended`, paramètres `date` (`U`), `released` (`R`), `version`, `before` (`U` + 1 jour), en `AAAA-MM-JJ` ; le texte les écrit en français (« 31 mars 2026 ») : « Les mises à jour de cette licence couvrent les versions publiées jusqu'au U inclus ; la version installée (…) a été publiée le R. Prolongez les mises à jour sur https://apps.marinails.site/passorde, ou installez la clé reçue par e-mail, ou revenez à une version publiée au plus tard le U. Quand le planificateur de tâches tourne, le rafraîchissement quotidien installe aussi de lui-même la clé prolongée. »
- Une valeur qui n'est pas une date `AAAA-MM-JJ` réelle : `malformed_date` (champ `updates_until`) ; une valeur qui n'est pas un texte : `invalid_field`.
- Le contrôle vient après `version_max` et avant les dates d'échéance.

## Échéance et période de grâce

Tous les instants sont à l'heure de Paris (`Europe/Paris`), en jours du calendrier : un jour de 23 ou 25 heures au changement d'heure compte pour un jour. `E` est `expires_at`, le dernier jour payé.

| État | Du (inclus) | Au (exclu) |
| --- | --- | --- |
| Valide | — | `E` + 1 jour, 00:00 : fin de la période payée |
| Grâce | `E` + 1 jour, 00:00 : l'avertissement commence | `E` + 15 jours, 00:00 : la synchronisation s'arrête |
| Expirée | `E` + 15 jours, 00:00 | — |

Aucune marge n'est ajoutée d'un côté ni de l'autre. `LicenceStatus::$graceEndsAt` vaut `E` + 15 jours à 00:00 (le premier instant non couvert) ; `graceLastDay()` et le motif affiché nomment `E` + 14 jours, le dernier jour entier de grâce. La tolérance de 24 heures ne vaut que pour `issued_at` (horloge en retard sur celle du service) : elle n'allonge aucune période. Le service de licences applique la même règle.
