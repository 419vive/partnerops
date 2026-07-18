<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiCredential;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApiCredential> */
final class ApiCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiCredential::class);
    }

    public function findActiveBySelector(string $selector): ?ApiCredential
    {
        return $this->createQueryBuilder('credential')
            ->innerJoin('credential.client', 'client')
            ->andWhere('credential.selector = :selector')
            ->andWhere('credential.revokedAt IS NULL')
            ->andWhere('client.isArchived = false')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function activeNameExists(Client $client, string $name): bool
    {
        return (int) $this->createQueryBuilder('credential')
            ->select('COUNT(credential.id)')
            ->andWhere('credential.client = :client')
            ->andWhere('LOWER(credential.name) = :name')
            ->andWhere('credential.revokedAt IS NULL')
            ->setParameter('client', $client)
            ->setParameter('name', mb_strtolower(trim($name)))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
