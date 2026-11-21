<?php

/*
 * Regenerates THIRD-PARTY.md from the installed composer dependencies.
 * Run with: composer third-party (also runs after every composer update).
 */

$root = dirname(__DIR__);
$composer = getenv('COMPOSER_BINARY') ?: 'composer';

/**
 * @return array<string, array{version: string, license: list<string>}>
 */
$licences = function (bool $noDev) use ($root, $composer): array {
    $command = sprintf(
        '%s licenses --format=json --no-interaction --working-dir=%s%s',
        escapeshellarg($composer),
        escapeshellarg($root),
        $noDev ? ' --no-dev' : '',
    );

    // composer install -q exports SHELL_VERBOSITY=-1 to its scripts, which would silence the nested command.
    putenv('SHELL_VERBOSITY=0');
    exec($command.' 2>&1', $output, $status);
    $text = implode("\n", $output);
    // composer may print a notice before the JSON (a plugin, a warning): read from the first brace.
    $start = strpos($text, '{');
    $data = $status === 0 && $start !== false ? json_decode(substr($text, $start), true) : null;

    if (! is_array($data)) {
        // Exit 1 with the reason, rather than dying on an exception (exit 255) in the middle of composer install.
        fwrite(STDERR, "composer licenses gave no usable answer (exit {$status}); THIRD-PARTY.md left as it is.\n".substr($text, 0, 500)."\n");
        exit(1);
    }

    $dependencies = $data['dependencies'] ?? [];
    ksort($dependencies);

    return $dependencies;
};

$table = function (array $packages): string {
    $rows = ['| Paquet | Version | Licence |', '| --- | --- | --- |'];

    foreach ($packages as $name => $package) {
        $rows[] = sprintf(
            '| %s | %s | %s |',
            $name,
            $package['version'],
            implode(', ', $package['license']) ?: 'non renseignée',
        );
    }

    return implode("\n", $rows);
};

$runtime = $licences(true);
$development = array_diff_key($licences(false), $runtime);

$document = <<<MD
# Composants tiers

Ce fichier est généré par `composer third-party`. Ne pas le modifier à la main.

Passorde Connect ne redistribue pas ces composants : composer les installe depuis leurs sources respectives, chacun sous sa propre licence. Les versions sont celles résolues le jour de la publication ; une installation peut recevoir des versions plus récentes dans les contraintes de `composer.json`, sous les mêmes licences.

## Dépendances d'exécution

{$table($runtime)}

## Dépendances de développement

{$table($development)}

MD;

file_put_contents($root.'/THIRD-PARTY.md', $document);

echo sprintf("THIRD-PARTY.md: %d runtime, %d development packages\n", count($runtime), count($development));
