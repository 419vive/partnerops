<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceRequest> */
final class ServiceRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceRequest::class);
    }

    public function findOneForClientByPublicId(Client $client, string $publicId): ?ServiceRequest
    {
        return $this->withListAssociations($this->createClientScopedQueryBuilder($client))
            ->andWhere('r.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ServiceRequest> */
    public function findPageForClient(
        Client $client,
        int $page = 1,
        int $perPage = 25,
        ?RequestStatus $status = null,
        ?RequestPriority $priority = null,
    ): array {
        return $this->findFilteredPage($page, $perPage, $client, $status, $priority);
    }

    /** @return list<ServiceRequest> */
    public function findFilteredPage(
        int $page = 1,
        int $perPage = 25,
        ?Client $client = null,
        ?RequestStatus $status = null,
        ?RequestPriority $priority = null,
        ?string $search = null,
        ?User $assignee = null,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return $this->withListAssociations(
            $this->buildFilteredQuery($client, $status, $priority, $search, $assignee),
        )
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(
        ?Client $client = null,
        ?RequestStatus $status = null,
        ?RequestPriority $priority = null,
        ?string $search = null,
        ?User $assignee = null,
    ): int {
        return (int) $this->buildFilteredQuery($client, $status, $priority, $search, $assignee)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ServiceRequest> */
    public function findPrioritizedQueue(int $limit = 50, ?Client $client = null, ?User $assignee = null): array
    {
        $qb = $this->withListAssociations($this->createClientScopedQueryBuilder($client))
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('terminal', [RequestStatus::Resolved->value, RequestStatus::Closed->value])
            ->addSelect("CASE WHEN r.priority = 'urgent' THEN 0 WHEN r.priority = 'high' THEN 1 WHEN r.priority = 'normal' THEN 2 ELSE 3 END AS HIDDEN priority_rank")
            ->addSelect('CASE WHEN r.dueAt IS NULL THEN 1 ELSE 0 END AS HIDDEN unscheduled_rank')
            ->orderBy('priority_rank', 'ASC')
            ->addOrderBy('unscheduled_rank', 'ASC')
            ->addOrderBy('r.dueAt', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->setMaxResults(max(1, min(200, $limit)));

        if ($assignee !== null) {
            $qb->andWhere('r.assignee = :assignee')->setParameter('assignee', $assignee);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array{open:int, overdue:int, dueSoon:int, unassigned:int} */
    public function dashboardCounts(
        \DateTimeImmutable $now,
        \DateTimeImmutable $dueSoon,
        ?Client $client = null,
    ): array {
        $qb = $this->createClientScopedQueryBuilder($client)
            ->select('COALESCE(SUM(CASE WHEN r.status NOT IN (:terminal) THEN 1 ELSE 0 END), 0) AS open_count')
            ->addSelect('COALESCE(SUM(CASE WHEN r.status NOT IN (:terminal) AND r.dueAt < :now THEN 1 ELSE 0 END), 0) AS overdue_count')
            ->addSelect('COALESCE(SUM(CASE WHEN r.status NOT IN (:terminal) AND r.dueAt >= :now AND r.dueAt <= :dueSoon THEN 1 ELSE 0 END), 0) AS due_soon_count')
            ->addSelect('COALESCE(SUM(CASE WHEN r.status NOT IN (:terminal) AND r.assignee IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_count')
            ->setParameter('terminal', [RequestStatus::Resolved->value, RequestStatus::Closed->value])
            ->setParameter('now', $now)
            ->setParameter('dueSoon', $dueSoon);

        /** @var array{open_count:string|int, overdue_count:string|int, due_soon_count:string|int, unassigned_count:string|int} $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'open' => (int) $row['open_count'],
            'overdue' => (int) $row['overdue_count'],
            'dueSoon' => (int) $row['due_soon_count'],
            'unassigned' => (int) $row['unassigned_count'],
        ];
    }

    /**
     * @return array<int, array{open:int, overdue:int, dueSoon:int, unassigned:int}> Indexed by client database ID.
     */
    public function dashboardCountsByClient(\DateTimeImmutable $now, \DateTimeImmutable $dueSoon): array
    {
        /** @var list<array{client_id:string|int, open_count:string|int, overdue_count:string|int, due_soon_count:string|int, unassigned_count:string|int}> $rows */
        $rows = $this->createQueryBuilder('request')
            ->select('IDENTITY(request.client) AS client_id')
            ->addSelect('COALESCE(SUM(CASE WHEN request.status NOT IN (:terminal) THEN 1 ELSE 0 END), 0) AS open_count')
            ->addSelect('COALESCE(SUM(CASE WHEN request.status NOT IN (:terminal) AND request.dueAt < :now THEN 1 ELSE 0 END), 0) AS overdue_count')
            ->addSelect('COALESCE(SUM(CASE WHEN request.status NOT IN (:terminal) AND request.dueAt >= :now AND request.dueAt <= :dueSoon THEN 1 ELSE 0 END), 0) AS due_soon_count')
            ->addSelect('COALESCE(SUM(CASE WHEN request.status NOT IN (:terminal) AND request.assignee IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_count')
            ->setParameter('terminal', [RequestStatus::Resolved->value, RequestStatus::Closed->value])
            ->setParameter('now', $now)
            ->setParameter('dueSoon', $dueSoon)
            ->groupBy('request.client')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['client_id']] = [
                'open' => (int) $row['open_count'],
                'overdue' => (int) $row['overdue_count'],
                'dueSoon' => (int) $row['due_soon_count'],
                'unassigned' => (int) $row['unassigned_count'],
            ];
        }

        return $counts;
    }

    private function buildFilteredQuery(
        ?Client $client,
        ?RequestStatus $status,
        ?RequestPriority $priority,
        ?string $search,
        ?User $assignee,
    ): QueryBuilder {
        $qb = $this->createClientScopedQueryBuilder($client);

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }
        if ($priority !== null) {
            $qb->andWhere('r.priority = :priority')->setParameter('priority', $priority->value);
        }
        if ($assignee !== null) {
            $qb->andWhere('r.assignee = :assignee')->setParameter('assignee', $assignee);
        }
        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(r.title) LIKE :search OR LOWER(r.description) LIKE :search')
                ->setParameter('search', '%'.strtolower(trim($search)).'%');
        }

        return $qb;
    }

    private function withListAssociations(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect('request_client')
            ->leftJoin('r.assignee', 'request_assignee')->addSelect('request_assignee');
    }

    /**
     * A null client represents a team-wide operational view, so archived clients
     * are excluded. An explicit client represents a scoped history view and must
     * remain readable after that client is archived.
     */
    private function createClientScopedQueryBuilder(?Client $client): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.client', 'request_client');

        if ($client === null) {
            return $qb->andWhere('request_client.isArchived = false');
        }

        return $qb
            ->andWhere('r.client = :client')
            ->setParameter('client', $client);
    }
}
