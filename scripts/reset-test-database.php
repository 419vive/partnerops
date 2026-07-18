<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Doctrine DBAL connection is unavailable.');
    }

    $params = $connection->getParams();
    $driver = (string) ($params['driver'] ?? '');
    $testToken = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? '';
    if (!is_string($testToken)) {
        throw new RuntimeException('TEST_TOKEN must be a string when provided.');
    }
    $expectedSuffix = '_test'.$testToken;

    if (str_contains($driver, 'sqlite')) {
        $path = $params['path'] ?? null;
        if (!is_string($path) || !str_ends_with(pathinfo($path, PATHINFO_FILENAME), $expectedSuffix)) {
            throw new RuntimeException('Refusing to reset a SQLite database without the configured test suffix.');
        }

        $databaseDirectory = realpath(dirname($path));
        $projectVarDirectory = realpath(dirname(__DIR__).'/var');
        if ($databaseDirectory === false || $projectVarDirectory === false
            || !str_starts_with($databaseDirectory.DIRECTORY_SEPARATOR, $projectVarDirectory.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to reset a SQLite database outside the project var directory.');
        }

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('The disposable SQLite test database could not be removed.');
        }
        if (!touch($path)) {
            throw new RuntimeException('The disposable SQLite test database could not be created.');
        }

        $target = $path;
    } else {
        $databaseName = $params['dbname'] ?? null;
        if (!is_string($databaseName) || !str_ends_with($databaseName, $expectedSuffix)) {
            throw new RuntimeException('Refusing to reset a database without the configured test suffix.');
        }

        $adminParams = $params;
        $adminParams['dbname'] = 'postgres';
        $adminConnection = DriverManager::getConnection($adminParams);

        try {
            $platform = $adminConnection->getDatabasePlatform();
            if (!$platform instanceof PostgreSQLPlatform) {
                throw new RuntimeException('Only SQLite and PostgreSQL test database resets are supported.');
            }

            $quotedDatabase = $platform->quoteSingleIdentifier($databaseName);
            $adminConnection->executeStatement(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $quotedDatabase));
            $adminConnection->executeStatement(sprintf('CREATE DATABASE %s', $quotedDatabase));
        } finally {
            $adminConnection->close();
        }

        $target = $databaseName;
    }

    fwrite(STDOUT, sprintf("Disposable test database reset: %s\n", $target));
} finally {
    $kernel->shutdown();
}
