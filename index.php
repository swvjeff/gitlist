<?php

/**
 * GitList 0.4
 * https://github.com/klaussilveira/gitlist
 */

/// Ugly hack to circumvent the redis session handler
ini_set('session.save_path', '/var/lib/php/session');

// Set the default timezone for systems without date.timezone set in php.ini
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

if (php_sapi_name() == 'cli-server' && file_exists(substr($_SERVER['REQUEST_URI'], 1))) {
    return false;
}

if (!is_writable(__DIR__ . DIRECTORY_SEPARATOR . 'cache')) {
    die(sprintf('The "%s" folder must be writable for GitList to run.', __DIR__ . DIRECTORY_SEPARATOR . 'cache'));
}

require 'vendor/autoload.php';

$config = GitList\Config::fromFile('config.ini');

if ($config->get('Date', 'timezone')) {
    date_default_timezone_set($config->get('Date', 'timezone'));
}

$app = require 'boot.php';
$app->run();

