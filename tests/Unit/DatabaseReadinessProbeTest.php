<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Health\DatabaseReadinessProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;

final class DatabaseReadinessProbeTest extends TestCase
{
    public function testProbeAppliesATransactionLocalTimeoutBeforeQuerying(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation($connection));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('SET LOCAL statement_timeout = 2000');
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn('1');

        (new DatabaseReadinessProbe($connection))->assertReady();
    }

    public function testProbeRejectsAnUnexpectedDatabaseResult(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('transactional')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation($connection));
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(\RuntimeException::class);

        (new DatabaseReadinessProbe($connection))->assertReady();
    }
}
