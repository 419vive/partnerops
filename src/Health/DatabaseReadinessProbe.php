<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final readonly class DatabaseReadinessProbe
{
    private const STATEMENT_TIMEOUT_MS = 2000;

    public function __construct(private Connection $connection)
    {
    }

    public function assertReady(): void
    {
        $result = $this->connection->transactional(function (Connection $connection): int {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                // SET LOCAL is scoped to this probe transaction and cannot leak
                // to normal application queries when a connection is reused.
                $connection->executeStatement(sprintf(
                    'SET LOCAL statement_timeout = %d',
                    self::STATEMENT_TIMEOUT_MS,
                ));
            }

            return (int) $connection->fetchOne('SELECT 1');
        });

        if ($result !== 1) {
            throw new \RuntimeException('The database readiness probe returned an unexpected result.');
        }
    }
}
