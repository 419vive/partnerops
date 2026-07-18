<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiCredential;
use App\Entity\IdempotencyRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\IdempotencyRecord i WHERE i.expiresAt <= :now',
        )->setParameter('now', $now)->execute();
    }
}
