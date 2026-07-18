<?php

use Symfony\Component\Dotenv\Dotenv;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
