<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEvent;
use App\Entity\Client;
use App\Entity\ServiceRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEvent> */
final class AuditEventRepository extends ServiceEntityRepository
{
    public const REQUEST_TIMELINE_LIMIT = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /** @return list<AuditEvent> */
    public function findPageForClient(Client $client, int $page = 1, int $perPage = 50, ?string $action = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')
            ->setParameter('client', $client)
            ->orderBy('a.occurredAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($action !== null && $action !== '') {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<AuditEvent> Most recent request activity, newest first. */
    public function findRequestTimeline(ServiceRequest $request, int $limit = self::REQUEST_TIMELINE_LIMIT): array
    {
        return $this->createQueryBuilder('event')
            ->leftJoin('event.actor', 'timeline_actor')->addSelect('timeline_actor')
            ->andWhere('event.client = :client')
            ->andWhere('event.subjectType = :subjectType')
            ->andWhere('event.subjectPublicId = :subjectPublicId')
            ->andWhere('event.action IN (:actions)')
            ->setParameter('client', $request->getClient())
            ->setParameter('subjectType', 'service_request')
            ->setParameter('subjectPublicId', $request->getPublicId())
            ->setParameter('actions', ['request.created', 'request.updated', 'request.status_changed'])
            ->orderBy('event.occurredAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults(max(1, min(self::REQUEST_TIMELINE_LIMIT, $limit)))
            ->getQuery()
            ->getResult();
    }
}
