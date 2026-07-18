<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel('test', false);
$kernel->boot();

try {
    $container = $kernel->getContainer()->get('test.service_container');
    $connection = $container->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Doctrine DBAL connection is unavailable.');
    }

    $predecessorType = $_SERVER['IDEMPOTENCY_PREDECESSOR_TYPE'] ?? $_ENV['IDEMPOTENCY_PREDECESSOR_TYPE'] ?? 'jsonb';
    if (!is_string($predecessorType) || !in_array($predecessorType, ['json', 'jsonb'], true)) {
        throw new RuntimeException('IDEMPOTENCY_PREDECESSOR_TYPE must be json or jsonb.');
    }

    $responseType = $connection->fetchOne(<<<'SQL'
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'idempotency_record'
          AND column_name = 'response_body'
        SQL);
    if ($responseType !== $predecessorType) {
        throw new RuntimeException(sprintf(
            'Expected the %s idempotency predecessor schema, received %s.',
            $predecessorType,
            is_string($responseType) ? $responseType : 'unknown',
        ));
    }

    $clientId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO client
            (public_id, name, slug, is_archived, archived_at, created_at, updated_at)
        VALUES
            (:public_id, 'CI Upgrade Client', 'ci-upgrade-client', false, NULL, NOW(), NOW())
        RETURNING id
        SQL, ['public_id' => (string) new Ulid()]);

    $adminId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO app_user
            (public_id, email, password_hash, display_name, role, client_id,
             is_active, deactivated_at, created_at, updated_at)
        VALUES
            (:public_id, 'ci-upgrade-admin@partnerops.test', 'not-used',
             'CI Upgrade Admin', 'admin', NULL, true, NULL, NOW(), NOW())
        RETURNING id
        SQL, ['public_id' => (string) new Ulid()]);

    $credentialId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO api_credential
            (public_id, client_id, name, selector, token_prefix, secret_hash,
             last_used_at, revoked_at, created_by_id, created_at)
        VALUES
            (:public_id, :client_id, 'CI Upgrade', 'ciupgrade1', 'ptk_ci_upgrade',
             :secret_hash, NULL, NULL, :admin_id, NOW())
        RETURNING id
        SQL, [
        'public_id' => (string) new Ulid(),
        'client_id' => $clientId,
        'secret_hash' => str_repeat('a', 64),
        'admin_id' => $adminId,
    ]);

    $requestPublicId = (string) new Ulid();
    $requestId = (int) $connection->fetchOne(<<<'SQL'
        INSERT INTO service_request
            (public_id, client_id, requester_id, created_by_credential_id,
             assignee_id, title, description, priority, status, due_at,
             resolved_at, closed_at, version, created_at, updated_at)
        VALUES
            (:public_id, :client_id, NULL, :credential_id, NULL,
             'CI upgrade request', 'Validates response replay across a schema upgrade.',
             'high', 'new', NULL, NULL, NULL, 1,
             TIMESTAMP '2026-07-18 00:00:00', TIMESTAMP '2026-07-18 00:00:00')
        RETURNING id
        SQL, [
        'public_id' => $requestPublicId,
        'client_id' => $clientId,
        'credential_id' => $credentialId,
    ]);

    $responseBody = [
        'id' => $requestPublicId,
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

    $responseCast = $predecessorType === 'jsonb' ? 'JSONB' : 'JSON';
    $connection->executeStatement(sprintf(<<<'SQL'
        INSERT INTO idempotency_record
            (api_credential_id, idempotency_key, request_fingerprint,
             service_request_id, response_status, response_body, created_at, expires_at)
        VALUES
            (:credential_id, 'ci-upgrade-order-check', :fingerprint,
             :request_id, 201, CAST(:response_body AS %s), NOW(), NOW() + INTERVAL '24 hours')
        SQL, $responseCast), [
        'credential_id' => $credentialId,
        'fingerprint' => str_repeat('b', 64),
        'request_id' => $requestId,
        'response_body' => json_encode($responseBody, JSON_THROW_ON_ERROR),
    ]);

    $storedBody = $connection->fetchOne(<<<'SQL'
        SELECT response_body::text
        FROM idempotency_record
        WHERE idempotency_key = 'ci-upgrade-order-check'
        SQL);
    if (!is_string($storedBody)) {
        throw new RuntimeException('The upgrade fixture response was not stored.');
    }
    $storedBody = json_decode($storedBody, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($storedBody)) {
        throw new RuntimeException('The upgrade fixture response is not a JSON object.');
    }
    $storedKeys = array_keys($storedBody);
    $presenterKeysPreserved = $storedKeys === array_keys($responseBody);
    if ($predecessorType === 'jsonb' && $presenterKeysPreserved) {
        throw new RuntimeException('The upgrade fixture did not reproduce JSONB key reordering.');
    }
    if ($predecessorType === 'json' && !$presenterKeysPreserved) {
        throw new RuntimeException('The published JSON predecessor did not preserve presenter key order.');
    }

    fwrite(STDOUT, sprintf("Pre-upgrade %s idempotency fixture created.\n", strtoupper($predecessorType)));
} finally {
    $kernel->shutdown();
}
