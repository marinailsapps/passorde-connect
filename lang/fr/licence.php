<?php

return [

    'title' => 'Licence Passorde Connect',
    'back' => 'Retour',
    'notice' => 'Passorde Connect : le composant de connecteurs de Passorde.',

    'field' => [
        'state' => 'État',
        'reason' => 'Motif',
        'licence_id' => 'Identifiant',
        'plan' => 'Offre',
        'connectors' => 'Connecteurs couverts',
        'company' => 'Société',
        'issued_at' => 'Émise le',
        'expires_at' => 'Expire le',
        'version_max' => 'Version maximale',
        'updates_until' => 'Mises à jour jusqu\'au',
        'last_refresh' => 'Dernier rafraîchissement',
    ],

    'value' => [
        'none' => '—',
        'never' => 'Jamais',
        'no_expiry' => 'Sans expiration',
        'no_limit' => 'Sans limite',
    ],

    'state' => [
        'valid' => 'Valide',
        'grace' => 'Période de grâce',
        'expired' => 'Expirée',
        'invalid' => 'Invalide',
    ],

    'reason' => [
        'not_installed' => "Aucune licence n'est installée.",
        'malformed_key' => "Ce texte n'est pas une clé de licence Passorde Connect : copiez la clé entière, elle commence par pck1.",
        'legacy_format' => "C'est une licence au format JSON, qui n'est pas acceptée : installez la clé de licence (une ligne qui commence par pck1.).",
        'unsupported_key_version' => "Cette clé est d'un format plus récent que cette version de Passorde Connect : mettez le paquet à jour.",
        'store_unavailable' => 'La licence enregistrée ne peut pas être lue : :detail',
        'missing_field' => 'Le champ obligatoire « :field » est absent.',
        'invalid_field' => 'Le champ « :field » a un format incorrect.',
        'unknown_kid' => "Cette licence est signée avec une clé inconnue (:kid) : elle n'est pas reconnue par cette version de Passorde Connect.",
        'bad_signature' => 'La signature de la licence est incorrecte.',
        'wrong_product' => 'Cette licence concerne un autre produit (:product), pas Passorde Connect.',
        'connector_not_covered' => 'Cette licence ne couvre pas le connecteur :connector (connecteurs couverts : :connectors).:notice',
        'bad_version_max' => 'La version maximale de la licence (:version_max) a un format incorrect.',
        'version_exceeded' => 'Cette licence couvre Passorde Connect jusqu\'à la version :version_max ; la version installée est :version.',
        'updates_ended' => 'Les mises à jour de cette licence couvrent les versions publiées jusqu\'au :date inclus ; la version installée (:version) a été publiée le :released. Prolongez les mises à jour sur :site, ou installez la clé reçue par e-mail, ou revenez à une version publiée au plus tard le :date. Quand le planificateur de tâches tourne, le rafraîchissement quotidien installe aussi de lui-même la clé prolongée.',
        'malformed_date' => 'Le champ « :field » doit être une date au format AAAA-MM-JJ.',
        'issued_in_future' => "La date d'émission de la licence (:date) est dans le futur.",
        'active' => "La licence est valide jusqu'au :date inclus.",
        'lifetime' => "La licence est valide et n'expire pas.",
        'in_grace' => "La période payée s'est terminée le :date ; la période de grâce court jusqu'au :last_day inclus. Renouvelez la licence sur :site, puis installez la clé reçue par e-mail ; quand le planificateur de tâches tourne, le rafraîchissement quotidien l'installe aussi de lui-même.",
        'expired' => "La période payée s'est terminée le :date et la période de grâce est terminée. Renouvelez la licence sur :site, puis installez la clé reçue par e-mail.",
    ],

    'form' => [
        'install_title' => 'Installer une licence',
        'paste' => 'Coller la clé de licence',
        'upload' => 'Ou choisir un fichier qui contient la clé',
        'install' => 'Installer',
        'refresh' => 'Rafraîchir la licence',
    ],

    'install' => [
        'success' => 'Licence installée.',
        'empty' => 'Collez une clé de licence ou choisissez un fichier.',
        'too_large' => 'Ce texte est trop long pour être une clé de licence.',
        'unreadable' => 'Le fichier « :path » est illisible.',
        'prompt' => 'Clé de licence (pck1.…)',
        'refused' => 'Licence refusée, la licence en place est conservée : :reason',
        'store_failed' => "La licence n'a pas pu être enregistrée : :reason",
    ],

    'refresh' => [
        'success' => 'Licence rafraîchie.',
        'failed' => 'Le rafraîchissement a échoué, la licence en place est conservée : :reason',
        'nothing' => 'Rien à rafraîchir : :reason.',
        'not_installed' => "aucune licence n'est installée",
        'lifetime' => 'licence à vie sans jeton de rafraîchissement',
        'no_licence' => "aucune licence vérifiable n'est installée",
        'no_token' => 'la licence ne contient pas de jeton de rafraîchissement',
        'http_status' => 'le service a répondu avec le statut :status',
        'network' => 'le service est injoignable',
        'unexpected' => "une erreur inattendue s'est produite",
        'bad_answer' => 'la réponse du service a été refusée (:reason)',
        'other_licence' => 'la réponse du service concerne une autre licence',
        'connector_dropped' => 'la clé reçue ne couvre plus :connector',
        'updates_earlier' => 'la clé reçue arrête les mises à jour plus tôt (:date) que la clé en place',
        'ends_earlier' => 'la clé reçue se termine plus tôt (:date) que la clé en place',
        'store_failed' => "la clé n'a pas pu être enregistrée",
    ],

    'command' => [
        'unknown_action' => 'Action inconnue. Actions possibles : show, install, refresh.',
    ],

];
