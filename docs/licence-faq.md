# FAQ licence

Cette page décrit le comportement du logiciel. Les droits et les conditions sont dans [LICENSE.md](../LICENSE.md) et [COMMERCIAL.md](../COMMERCIAL.md).

## Que signifie chaque état ?

| État | Appels du connecteur | `passorde:status` | Sens |
| --- | --- | --- | --- |
| Valide | Oui | 0 | Licence en cours, ou sans expiration |
| Période de grâce | Oui | 1 | Période payée terminée depuis moins de 14 jours |
| Expirée | Non | 2 | Période de grâce terminée |
| Invalide | Non | 2 | Absente, altérée, pour un autre produit, qui ne couvre pas ce connecteur, ou qui ne couvre pas cette version |

Quand les appels sont bloqués, rien n'est supprimé : le miroir et les journaux restent lisibles, `passorde:status` et la page de licence répondent, et une exécution de synchronisation est enregistrée `failed` avec le motif. Tout reprend dès qu'une licence valide est installée.

## Qu'est-ce qu'une clé de licence ?

Une ligne de texte qui commence par `pck1.`, reçue par e-mail. Elle contient la licence signée (titulaire, offre, dates, connecteurs couverts, version maximale) et le jeton qui sert à la rafraîchir (renouvellement d'un abonnement, prolongation des mises à jour d'une licence à vie). La garder comme un mot de passe. Format : [licence-key.md](licence-key.md). Une licence au format JSON n'est pas acceptée : seule la clé l'est.

## Quels connecteurs une licence couvre-t-elle ?

Ceux de sa liste `connectors`, signée avec le reste : aujourd'hui le seul connecteur publié, proposé sous le nom « Passorde Connect — Evoliz » (compatible avec Evoliz, non affilié à Evoliz SAS / Visma). Un connecteur qui n'est pas dans la liste n'appelle pas son service, et la clé est refusée à l'installation : le motif nomme le connecteur et les connecteurs couverts. `php artisan passorde:licence show` et la page de licence affichent la liste.

## Passorde Connect contacte-t-il un serveur pour vérifier la licence ?

Non. La vérification est hors ligne : une signature Ed25519 contrôlée avec une clé publique incluse dans le paquet. Seul le rafraîchissement utilise le réseau.

## Faut-il une licence pour évaluer Passorde Connect ?

La lecture, l'étude, l'installation et l'exécution locale pour évaluation sont libres : voir [LICENSE.md](../LICENSE.md). Sans clé valide, aucun connecteur ne contacte son service : l'évaluation sans clé porte sur le code, la documentation, l'installation et la page de licence. Le garde de licence s'applique pourtant à tout appel d'un connecteur, vers son service comme vers son simulateur (voir la documentation du connecteur, sous `connectors/`) : sans clé valide, `passorde:ping` et `passorde:sync` s'arrêtent avant d'envoyer quoi que ce soit. Pour les tests automatisés de l'application, `Passorde\Testing\TestLicence` installe une licence de test : voir [Intégration dans Passorde](install.md#tests-de-lapplication).

## Existe-t-il une licence de développement ?

Non. Aucun réglage, aucune variable d'environnement et aucune commande n'ajoute une clé de signature reconnue, quel que soit `APP_ENV` : les clés reconnues sont fixées dans le code (`Passorde\Licence\PublicKeys`). En faire reconnaître une autre demande de modifier du code : changer `PublicKeys`, ou faire lier par l'application un autre vérificateur, comme le fait `TestLicence` dans les tests. La licence l'interdit (voir [LICENSE.md](../LICENSE.md), article 3). `TestLicence` ne fait pas partie du code chargé du paquet : l'application doit l'ajouter à son `autoload-dev`, il ne fonctionne que si l'environnement est exactement `testing`, et il n'agit que si un test l'appelle.

## Quand une licence expire-t-elle exactement ?

`expires_at` est le dernier jour payé. La période payée se termine à minuit, heure de Paris, à la fin de ce jour : l'avertissement commence à cet instant. La synchronisation s'arrête 14 jours plus tard, à minuit heure de Paris (14 jours du calendrier, changement d'heure compris). Aucune marge n'est ajoutée. Une licence sans date (`expires_at` nul) n'expire pas.

Par exemple, une licence dont `expires_at` vaut 2026-11-04 est valide jusqu'au 2026-11-04 23:59:59 ; la grâce court du 2026-11-05 00:00 au 2026-11-19 00:00 exclu. Le motif affiché nomme le dernier jour entier de grâce : « la période de grâce court jusqu'au 18 novembre 2026 inclus », suivi de l'adresse où renouveler. Règle exacte : [licence-key.md](licence-key.md#échéance-et-période-de-grâce). Pour une application, `LicenceStatus::$graceEndsAt` donne l'instant exact de fin (le premier qui n'est plus couvert) et `LicenceStatus::graceLastDay()` ce dernier jour.

## À quoi sert la période de grâce ?

À ne pas interrompre l'activité pour un renouvellement en retard. Tout fonctionne, `passorde:status` renvoie 1 et indique la date de fin. Elle n'est pas une prolongation : elle se termine à la date prévue.

## Qu'est-ce que `version_max` ?

La dernière version du paquet couverte par la licence.

- `1.x` couvre toute version 1 ; `1.4` couvre jusqu'à la 1.4 incluse, correctifs compris.
- Une pré-version compte comme la version qu'elle prépare : `1.0.0-beta.1` est couverte par `1.0` et `1.x`, et `1.5.0-beta.1` ne l'est pas par `1.4`.
- Si la version installée dépasse `version_max`, la licence est invalide, même avant sa date d'expiration. Le message donne les deux versions.

Il suffit alors de revenir à une version couverte, ou d'installer une licence qui couvre la version en place.

## Qu'est-ce que `updates_until` ?

La date de fin des mises à jour d'une licence à vie : la licence couvre les versions publiées au plus tard ce jour-là (chaque version porte sa date de publication, `Passorde\Passorde::RELEASED_AT`). Une clé sans `updates_until`, comme celle d'un abonnement, n'a pas cette limite. Sur une version plus récente, la licence est invalide jusqu'à la prolongation des mises à jour ou le retour à une version couverte ; sur une version plus ancienne, elle reste valide sans date de fin.

## Comment fonctionne le rafraîchissement ?

Chaque jour, quand le planificateur de tâches tourne, Passorde demande la clé à jour au service de licences, avec le jeton que porte la clé en place, tant que le connecteur est activé, sans autre réglage pour l'arrêter. (`PASSORDE_LICENCE_REFRESH_ENABLED` ne concerne que l'entrée que le composant planifie lui-même ; Passorde la laisse désactivée.) Aucun réglage n'est nécessaire : l'adresse du service est dans le paquet (`PASSORDE_LICENCE_REFRESH_URL` ne sert qu'à la remplacer, pour un service de test). La clé reçue n'est enregistrée, par le stockage de la clé, que si elle n'est pas moins bonne que la clé en place : valide, même licence, mêmes connecteurs au moins, et une échéance qui n'est pas plus proche. Tout échec (réseau, 401, 500, signature incorrecte, clé moins bonne) laisse la clé en place et écrit un avertissement. `php artisan passorde:licence refresh` le fait à la demande. Un renouvellement peut aussi s'installer à la main.

Quand il n'y a rien à rafraîchir, la commande ne demande rien, n'écrit aucun avertissement et se termine avec le code 0 en affichant une ligne « Rien à rafraîchir : … » :

- licence sans `licence_token` (une licence à vie avec un jeton est rafraîchie, pour recevoir une prolongation de ses mises à jour) ;
- aucune licence installée : c'est `passorde:status` et la page de licence qui le signalent, pas une erreur nocturne.

Les vrais échecs (réseau, statut HTTP autre que 200, réponse refusée, clé en place dont la signature ne se vérifie pas, clé reçue moins bonne) gardent le code 1 et l'avertissement. `Passorde\Licence\LicenceRefresher::isRefreshable(LicenceStatus $status): bool` dit si un rafraîchissement a quelque chose à demander, pour qu'une application puisse ne pas le planifier.

## Que se passe-t-il si le vendeur s'arrête ?

Passorde Connect n'a besoin d'aucun service du vendeur pour fonctionner, donc techniquement :

- une licence sans expiration continue de fonctionner sur les versions qu'elle couvre ;
- une licence à durée fixe fonctionne jusqu'à la fin de sa période payée, plus les 14 jours de grâce, puis les appels sont bloqués : plus personne ne pourrait émettre une nouvelle clé ;
- le rafraîchissement échoue sans conséquence : la clé en place reste valable ;
- les données restent dans le service connecté et dans vos tables.

Cette réponse ne décrit que le logiciel. Elle n'ajoute aucun engagement au contrat.

## Une licence à vie expire-t-elle ?

Non. Elle donne le droit d'utiliser la version majeure en cours le jour de l'achat (par exemple 1.x), sans date de fin, avec les mises à jour pendant 12 mois. Concrètement, elle couvre les versions 1.x publiées jusqu'à 12 mois après l'achat, ou jusqu'à la fin d'une prolongation payée des mises à jour ; ces versions peuvent être utilisées sans date de fin. Une version publiée après n'est pas couverte. Les versions majeures ultérieures ne sont pas comprises dans la licence à vie.

Les 12 mois de mises à jour sont inscrits dans la clé (`updates_until`). Une version publiée au plus tard ce jour-là fonctionne sans date de fin ; une version publiée après est refusée avec le motif « Les mises à jour de cette licence couvrent les versions publiées jusqu'au … inclus … Prolongez les mises à jour sur https://apps.marinails.site/passorde, ou installez la clé reçue par e-mail, ou revenez à une version publiée au plus tard le … » : les appels du connecteur s'arrêtent, l'application continue sans eux, et revenir à la version précédente suffit. La prolongation des mises à jour (12 mois de plus, voir [COMMERCIAL.md](../COMMERCIAL.md)) arrive par e-mail ; quand le planificateur de tâches tourne, le rafraîchissement quotidien l'installe aussi de lui-même.

## Comment obtenir ou renouveler une licence ?

Voir [COMMERCIAL.md](../COMMERCIAL.md).
