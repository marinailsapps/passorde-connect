<?php

/*
 * A stand-in for the Evoliz API, on fictional data. It needs PHP and nothing else:
 *
 *     php -S 127.0.0.1:8090 tools/mock/index.php
 *
 * See docs/mock.md. PASSORDE_MOCK_TODAY fixes the date the data is built around,
 * and PASSORDE_MOCK_STATE the file that holds it.
 */

require __DIR__.'/src/Answer.php';
require __DIR__.'/src/Rows.php';
require __DIR__.'/src/Seed.php';
require __DIR__.'/src/Store.php';
require __DIR__.'/src/Mock.php';

use Passorde\Mock\Mock;
use Passorde\Mock\Store;

$file = getenv('PASSORDE_MOCK_STATE') ?: sys_get_temp_dir().'/passorde-mock-'.$_SERVER['SERVER_PORT'].'.json';

$answer = (new Store($file))->transaction(fn (array $state): array => (new Mock($state))->handle(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    (string) file_get_contents('php://input'),
    'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1'),
));

http_response_code($answer->status);
header('Content-Type: application/json');

foreach ($answer->headers as $name => $value) {
    header($name.': '.$value);
}

echo json_encode($answer->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
