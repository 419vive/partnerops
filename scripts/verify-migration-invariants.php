<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Uid\Ulid;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Run an operation in its own transaction and require the expected PostgreSQL
 * SQLSTATE. Doctrine rolls the failed transaction back before control returns.
 */
function expectSqlState(Connection $connection, string $expected, Closure $operation): void
{
    try {
        $connection->transactional($operation);
    } catch (DriverException $exception) {
        if ($exception->getSQLState() === $expected) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Expected PostgreSQL SQLSTATE %s, received %s.',
            $expected,
            $exception->getSQLState() ?? 'none',
        ), previous: $exception);
    }

    throw new RuntimeException(sprintf(
        'The database accepted an operation that should fail with SQLSTATE %s.',
        $expected,
    ));
}

function migrationCheckId(): string
{
    return (string) new Ulid();
}

$kernel = new Kernel('test', false);
$kernel->boot();
$runSuffix = strtolower(bin2hex(random_bytes(6)));

try {
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Doctrine DBAL connection is unavailable.');
    }

    $constraintType = $connection->fetchOne(<<<'SQL'
        SELECT contype
        FROM pg_constraint
        WHERE conname = 'excl_allowance_period_overlap'
        SQL);
    if ($constraintType !== 'x') {
        throw new RuntimeException('The allowance exclusion constraint is missing from the migrated schema.');
    }

    $triggerState = $connection->fetchOne(<<<'SQL'
        SELECT tgenabled
        FROM pg_trigger
        WHERE tgname = 'audit_event_immutable' AND NOT tgisinternal
        SQL);
    if ($triggerState !== 'O') {
        throw new RuntimeException('The immutable audit trigger is missing or disabled.');
    }

    $idempotencyResponseType = $connection->fetchOne(<<<'SQL'
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'idempotency_record'
          AND column_name = 'response_body'
        SQL);
    if ($idempotencyResponseType !== 'json') {
        throw new RuntimeException('The idempotency response column must preserve JSON object key order.');
    }

    $upgradedResponse = $connection->fetchOne(<<<'SQL'
        SELECT response_body::text
        FROM idempotency_record
        WHERE idempotency_key = 'ci-upgrade-order-check'
        SQL);
    if (!is_string($upgradedResponse)) {
        throw new RuntimeException('The idempotency upgrade fixture is missing.');
    }
    $upgradedResponse = json_decode($upgradedResponse, true, flags: JSON_THROW_ON_ERROR);
    $expectedResponseKeys = [
        'id',
        'title',
        'description',
        'priority',
        'status',
        'dueAt',
        'assignee',
        'createdAt',
        'updatedAt',
        'comments',
        'commentsPagination',
    ];
    if (!is_array($upgradedResponse) || array_keys($upgradedResponse) !== $expectedResponseKeys) {
        throw new RuntimeException('The upgraded idempotency response does not preserve presenter key order.');
    }
    $responseId = $upgradedResponse['id'] ?? null;
    if (!is_string($responseId) || !preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $responseId)) {
        throw new RuntimeException('The upgraded idempotency response lost its request identifier.');
    }
    $expectedUpgradedResponse = [
        'id' => $responseId,
        'title' => 'CI upgrade request',
        'description' => 'Validates response replay across a schema upgrade.',
        'priority' => 'high',
        'status' => 'new',
        'dueAt' => null,
        'assignee' => null,
        'createdAt' => '2026-07-18T00:00:00Z',
        'updatedAt' => '2026-07-18T00:00:00Z',
        'comments' => [],
        'commentsPagination' => [
            'page' => 1,
            'perPage' => 50,
            'total' => 0,
            'pages' => 1,
        ],
    ];
    if ($upgradedResponse !== $expectedUpgradedResponse) {
        throw new RuntimeException('The idempotency upgrade changed cached response values.');
    }
    $pagination = $upgradedResponse['commentsPagination'] ?? null;
    if (!is_array($pagination) || array_keys($pagination) !== ['page', 'perPage', 'total', 'pages']) {
        throw new RuntimeException('The upgraded pagination response does not preserve presenter key order.');
    }

    $clientId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO client
            (public_id, name, slug, is_archived, archived_at, created_at, updated_at)
        VALUES
            (:public_id, 'CI Migration Client', :slug, false, NULL, NOW(), NOW())
        RETURNING id
        SQL, [
        'public_id' => migrationCheckId(),
        'slug' => 'ci-migration-'.$runSuffix,
    ]);

    expectSqlState(
        $connection,
        '23505',
        static fn (Connection $connection): int => $connection->executeStatement(<<<'SQL'
            INSERT INTO client
                (public_id, name, slug, is_archived, archived_at, created_at, updated_at)
            VALUES
                (:public_id, 'Duplicate CI Client', :slug, false, NULL, NOW(), NOW())
            SQL, [
            'public_id' => migrationCheckId(),
            'slug' => 'ci-migration-'.$runSuffix,
        ]),
    );

    $adminId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO app_user
            (public_id, email, password_hash, display_name, role, client_id,
             is_active, deactivated_at, created_at, updated_at)
        VALUES
            (:public_id, :email, 'not-used',
             'CI Admin', 'admin', NULL, true, NULL, NOW(), NOW())
        RETURNING id
        SQL, [
        'public_id' => migrationCheckId(),
        'email' => 'ci-migration-'.$runSuffix.'@partnerops.test',
    ]);

    $connection->executeStatement(<<<'SQL'
        INSERT INTO allowance_period
            (public_id, client_id, starts_on, ends_on, included_minutes,
             created_by_id, created_at)
        VALUES
            (:public_id, :client_id, DATE '2026-07-01', DATE '2026-07-31',
             600, :admin_id, NOW())
        SQL, [
        'public_id' => migrationCheckId(),
        'client_id' => $clientId,
        'admin_id' => $adminId,
    ]);

    expectSqlState(
        $connection,
        '23P01',
        static fn (Connection $connection): int => $connection->executeStatement(<<<'SQL'
            INSERT INTO allowance_period
                (public_id, client_id, starts_on, ends_on, included_minutes,
                 created_by_id, created_at)
            VALUES
                (:public_id, :client_id, DATE '2026-07-15', DATE '2026-08-15',
                 600, :admin_id, NOW())
            SQL, [
            'public_id' => migrationCheckId(),
            'client_id' => $clientId,
            'admin_id' => $adminId,
        ]),
    );

    $auditId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO audit_event
            (public_id, client_id, actor_id, actor_type, "action", subject_type,
             subject_public_id, metadata, trace_id, occurred_at)
        VALUES
            (:public_id, :client_id, :actor_id, 'user', 'ci.migration_check',
             'database', NULL, '{}'::jsonb, 'ci-migration-check', NOW())
        RETURNING id
        SQL, [
        'public_id' => migrationCheckId(),
        'client_id' => $clientId,
        'actor_id' => $adminId,
    ]);

    expectSqlState(
        $connection,
        '55000',
        static fn (Connection $connection): int => $connection->executeStatement(
            'UPDATE audit_event SET metadata = metadata WHERE id = :id',
            ['id' => $auditId],
        ),
    );
    expectSqlState(
        $connection,
        '55000',
        static fn (Connection $connection): int => $connection->executeStatement(
            'DELETE FROM audit_event WHERE id = :id',
            ['id' => $auditId],
        ),
    );

    fwrite(STDOUT, "Migration uniqueness, exclusion, and immutability invariants verified.\n");
} finally {
    $kernel->shutdown();
}
