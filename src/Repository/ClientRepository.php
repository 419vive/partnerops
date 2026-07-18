<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Client> */
final class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    public function findOneActiveByPublicId(string $publicId): ?Client
    {
        return $this->findOneBy(['publicId' => $publicId, 'isArchived' => false]);
    }

    public function findOneActiveBySlug(string $slug): ?Client
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.slug) = :slug')
            ->andWhere('c.isArchived = false')
            ->setParameter('slug', strtolower(trim($slug)))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
