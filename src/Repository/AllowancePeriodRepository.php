<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AllowancePeriod;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AllowancePeriod> */
final class AllowancePeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllowancePeriod::class);
    }

    public function findApplicable(Client $client, \DateTimeImmutable $date): ?AllowancePeriod
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')
            ->andWhere('a.startsOn <= :date')
            ->andWhere('a.endsOn >= :date')
            ->setParameter('client', $client)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<AllowancePeriod> */
    public function findApplicableForAllClients(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('allowance')
            ->innerJoin('allowance.client', 'allowance_client')->addSelect('allowance_client')
            ->andWhere('allowance.startsOn <= :date')
            ->andWhere('allowance.endsOn >= :date')
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    public function hasOverlap(Client $client, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.client = :client')
            ->andWhere('a.startsOn <= :endsOn')
            ->andWhere('a.endsOn >= :startsOn')
            ->setParameter('client', $client)
            ->setParameter('startsOn', $startsOn, Types::DATE_IMMUTABLE)
            ->setParameter('endsOn', $endsOn, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countCurrentOverBudget(\DateTimeImmutable $date, ?Client $client = null): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM allowance_period a
            INNER JOIN client c ON c.id = a.client_id
            LEFT JOIN (
                SELECT allowance_period_id, SUM(minutes) AS used_minutes
                FROM time_entry
                WHERE approval_status = 'approved'
                GROUP BY allowance_period_id
            ) usage ON usage.allowance_period_id = a.id
            WHERE :work_date BETWEEN a.starts_on AND a.ends_on
              AND COALESCE(usage.used_minutes, 0) > a.included_minutes
            SQL;
        $params = ['work_date' => $date->format('Y-m-d')];
        $types = ['work_date' => ParameterType::STRING];

        if ($client !== null) {
            if ($client->getId() === null) {
                throw new \InvalidArgumentException('Client must be persisted before querying allowance usage.');
            }
            $sql .= ' AND a.client_id = :client_id';
            $params['client_id'] = $client->getId();
            $types['client_id'] = ParameterType::INTEGER;
        } else {
            $sql .= ' AND c.is_archived = FALSE';
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params, $types);
    }
}
