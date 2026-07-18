<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve idempotency response key order for byte-identical replay';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE idempotency_record DROP CONSTRAINT chk_idempotency_response');
        $this->addSql(<<<'SQL'
            ALTER TABLE idempotency_record
            ALTER COLUMN response_body TYPE JSON
            USING json_build_object(
                    'id', response_body -> 'id',
                    'title', response_body -> 'title',
                    'description', response_body -> 'description',
                    'priority', response_body -> 'priority',
                    'status', response_body -> 'status',
                    'dueAt', response_body -> 'dueAt',
                    'assignee', response_body -> 'assignee',
                    'createdAt', response_body -> 'createdAt',
                    'updatedAt', response_body -> 'updatedAt',
                    'comments', response_body -> 'comments',
                    'commentsPagination', json_build_object(
                        'page', response_body -> 'commentsPagination' -> 'page',
                        'perPage', response_body -> 'commentsPagination' -> 'perPage',
                        'total', response_body -> 'commentsPagination' -> 'total',
                        'pages', response_body -> 'commentsPagination' -> 'pages'
                    )
                )
            SQL);
        $this->addSql("ALTER TABLE idempotency_record ADD CONSTRAINT chk_idempotency_response CHECK (json_typeof(response_body) = 'object')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE idempotency_record DROP CONSTRAINT chk_idempotency_response');
        $this->addSql('ALTER TABLE idempotency_record ALTER COLUMN response_body TYPE JSONB USING response_body::jsonb');
        $this->addSql("ALTER TABLE idempotency_record ADD CONSTRAINT chk_idempotency_response CHECK (jsonb_typeof(response_body) = 'object')");
    }
}
