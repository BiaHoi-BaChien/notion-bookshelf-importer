<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

$paths = require __DIR__.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/paths.php';

$uri = trim($uri, '/');

if ($uri !== '' && file_exists($paths['public'].'/'.$uri)) {
    return false;
}

require_once $paths['public'].'/index.php';
