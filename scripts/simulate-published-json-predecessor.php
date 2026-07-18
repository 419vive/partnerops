<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Doctrine DBAL connection is unavailable.');
    }

    $responseType = $connection->fetchOne(<<<'SQL'
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'idempotency_record'
          AND column_name = 'response_body'
        SQL);
    if ($responseType !== 'jsonb') {
        throw new RuntimeException('The published JSON predecessor simulation requires the original JSONB schema.');
    }

    $connection->transactional(static function (Connection $connection): void {
        $connection->executeStatement('ALTER TABLE idempotency_record DROP CONSTRAINT chk_idempotency_response');
        $connection->executeStatement('ALTER TABLE idempotency_record ALTER COLUMN response_body TYPE JSON USING response_body::json');
        $connection->executeStatement("ALTER TABLE idempotency_record ADD CONSTRAINT chk_idempotency_response CHECK (json_typeof(response_body) = 'object')");
    });

    $responseType = $connection->fetchOne(<<<'SQL'
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'idempotency_record'
          AND column_name = 'response_body'
        SQL);
    if ($responseType !== 'json') {
        throw new RuntimeException('The published JSON predecessor simulation failed.');
    }

    fwrite(STDOUT, "Published JSON predecessor schema simulated.\n");
} finally {
    $kernel->shutdown();
}
