<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AllowancePeriod;
use App\Entity\Client;
use App\Entity\ServiceRequest;
use App\Entity\TimeEntry;
use App\Enum\ApprovalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TimeEntry> */
final class TimeEntryRepository extends ServiceEntityRepository
{
    public const PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    public function sumApprovedMinutes(AllowancePeriod $period): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.minutes), 0)')
            ->andWhere('t.allowancePeriod = :period')
            ->andWhere('t.approvalStatus = :approved')
            ->setParameter('period', $period)
            ->setParameter('approved', ApprovalStatus::Approved->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<AllowancePeriod> $periods
     * @return array<int, int> Indexed by allowance period database ID.
     */
    public function sumApprovedMinutesForPeriods(array $periods): array
    {
        if ($periods === []) {
            return [];
        }

        /** @var list<array{period_id:string|int, used_minutes:string|int}> $rows */
        $rows = $this->createQueryBuilder('entry')
            ->select('IDENTITY(entry.allowancePeriod) AS period_id')
            ->addSelect('COALESCE(SUM(entry.minutes), 0) AS used_minutes')
            ->andWhere('entry.allowancePeriod IN (:periods)')
            ->andWhere('entry.approvalStatus = :approved')
            ->setParameter('periods', $periods)
            ->setParameter('approved', ApprovalStatus::Approved->value)
            ->groupBy('entry.allowancePeriod')
            ->getQuery()
            ->getArrayResult();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['period_id']] = (int) $row['used_minutes'];
        }

        return $usage;
    }

    /** @return list<TimeEntry> */
    public function findClientVisible(Client $client, AllowancePeriod $period): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.allowancePeriod', 'a')
            ->andWhere('t.allowancePeriod = :period')
            ->andWhere('a.client = :client')
            ->andWhere('t.approvalStatus = :approved')
            ->andWhere('t.isClientVisible = true')
            ->setParameter('period', $period)
            ->setParameter('client', $client)
            ->setParameter('approved', ApprovalStatus::Approved->value)
            ->orderBy('t.workDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<TimeEntry> */
    public function findRecentForRequest(
        ServiceRequest $request,
        bool $clientVisibleOnly,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $qb = $this->createQueryBuilder('entry')
            ->innerJoin('entry.author', 'entry_author')->addSelect('entry_author')
            ->andWhere('entry.serviceRequest = :request')
            ->setParameter('request', $request)
            ->orderBy('entry.createdAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($clientVisibleOnly) {
            $qb->andWhere('entry.approvalStatus = :approved')
                ->andWhere('entry.isClientVisible = true')
                ->setParameter('approved', ApprovalStatus::Approved->value);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForRequest(ServiceRequest $request, bool $clientVisibleOnly): int
    {
        $qb = $this->createQueryBuilder('entry')
            ->select('COUNT(entry.id)')
            ->andWhere('entry.serviceRequest = :request')
            ->setParameter('request', $request);

        if ($clientVisibleOnly) {
            $qb->andWhere('entry.approvalStatus = :approved')
                ->andWhere('entry.isClientVisible = true')
                ->setParameter('approved', ApprovalStatus::Approved->value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
