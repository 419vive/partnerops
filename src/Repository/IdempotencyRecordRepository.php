<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiCredential;
use App\Entity\IdempotencyRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IdempotencyRecord> */
final class IdempotencyRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyRecord::class);
    }

    public function findCurrent(ApiCredential $credential, string $key, \DateTimeImmutable $now): ?IdempotencyRecord
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.apiCredential = :credential')
            ->andWhere('i.idempotencyKey = :key')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('credential', $credential)
            ->setParameter('key', $key)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteExpired(\DateTimeImmutable $now, int $batchSize = 1000): int
    {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new \InvalidArgumentException('Idempotency cleanup batch size must be between 1 and 10000.');
        }

        $deleted = $this->getEntityManager()->getConnection()->executeStatement(<<<'SQL'
            DELETE FROM idempotency_record
            WHERE id IN (
                SELECT id
                FROM idempotency_record
                WHERE expires_at <= :now
                ORDER BY expires_at ASC, id ASC
                LIMIT :batch_size
            )
            SQL, [
            'now' => $now,
            'batch_size' => $batchSize,
        ], [
            'now' => Types::DATETIME_IMMUTABLE,
            'batch_size' => ParameterType::INTEGER,
        ]);
        if (!is_int($deleted)) {
            throw new \RuntimeException('The database returned an invalid idempotency cleanup count.');
        }

        return $deleted;
    }
}
