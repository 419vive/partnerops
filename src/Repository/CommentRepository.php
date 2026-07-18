<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Comment> */
final class CommentRepository extends ServiceEntityRepository
{
    public const PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /** @return list<Comment> One newest-first page, returned chronologically within the page. */
    public function findChronologicalForRequest(
        ServiceRequest $request,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
    ): array
    {
        return $this->paginatedChronological(
            $this->createQueryBuilder('c')
                ->andWhere('c.serviceRequest = :request')
                ->setParameter('request', $request),
            $page,
            $perPage,
        );
    }

    /** @return list<Comment> One newest-first client-visible page, chronological within the page. */
    public function findClientVisible(
        Client $client,
        ServiceRequest $request,
        int $page = 1,
        int $perPage = self::PAGE_SIZE,
    ): array
    {
        return $this->paginatedChronological($this->createQueryBuilder('c')
            ->innerJoin('c.serviceRequest', 'r')
            ->andWhere('c.serviceRequest = :request')
            ->andWhere('r.client = :client')
            ->andWhere('c.isInternal = false')
            ->setParameter('request', $request)
            ->setParameter('client', $client), $page, $perPage);
    }

    public function countForRequest(ServiceRequest $request): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->andWhere('comment.serviceRequest = :request')
            ->setParameter('request', $request)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countClientVisible(Client $client, ServiceRequest $request): int
    {
        return (int) $this->createQueryBuilder('comment')
            ->select('COUNT(comment.id)')
            ->innerJoin('comment.serviceRequest', 'request')
            ->andWhere('comment.serviceRequest = :serviceRequest')
            ->andWhere('request.client = :client')
            ->andWhere('comment.isInternal = false')
            ->setParameter('serviceRequest', $request)
            ->setParameter('client', $client)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Comment> */
    private function paginatedChronological(QueryBuilder $qb, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $result = $qb
            ->innerJoin('c.author', 'comment_author')->addSelect('comment_author')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $comments = [];
        foreach ($result as $comment) {
            if (!$comment instanceof Comment) {
                throw new \LogicException('Comment query returned an unexpected result.');
            }
            $comments[] = $comment;
        }

        return array_reverse($comments);
    }
}
