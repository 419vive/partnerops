<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;

require dirname(__DIR__).'/vendor/autoload.php';

/*
 * Doctrine ORM cannot represent PostgreSQL exclusion constraints or expression
 * indexes, and DBAL normalizes partial-index predicates during introspection.
 * Require the exact known difference set so every other mapping/schema drift
 * still fails the release gate.
 */
$expectedDifferences = [
    "CREATE INDEX idx_request_open_due ON service_request (due_at, priority) WHERE status NOT IN ('closed', 'resolved')",
    'DROP INDEX excl_allowance_period_overlap',
    'DROP INDEX idx_request_open_due',
    'DROP INDEX uniq_credential_active_name',
];

$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
$kernel = new Kernel($environment, $debug);
$kernel->boot();

try {
    $entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
    if (!$entityManager instanceof EntityManagerInterface) {
        throw new RuntimeException('Doctrine ORM entity manager is unavailable.');
    }

    $actualDifferences = array_map(
        static fn (string $sql): string => rtrim(trim($sql), ';'),
        (new SchemaValidator($entityManager))->getUpdateSchemaList(),
    );

    sort($expectedDifferences, SORT_STRING);
    sort($actualDifferences, SORT_STRING);

    if ($actualDifferences !== $expectedDifferences) {
        $format = static fn (array $statements): string => $statements === []
            ? '  (none)'
            : '  '.implode(";\n  ", $statements).';';

        throw new RuntimeException(sprintf(
            "Unexpected Doctrine schema difference.\nExpected only:\n%s\nActual:\n%s",
            $format($expectedDifferences),
            $format($actualDifferences),
        ));
    }

    fwrite(STDOUT, "Doctrine mapping/schema contract and PostgreSQL-specific index exceptions verified.\n");
} finally {
    $kernel->shutdown();
}
