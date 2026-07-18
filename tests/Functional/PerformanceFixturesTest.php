<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\DataFixtures\PerformanceFixtures;
use App\Entity\ServiceRequest;
use App\Enum\RequestStatus;
use App\Repository\ServiceRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PerformanceFixturesTest extends KernelTestCase
{
    public function testPerformanceGroupLoadsTenThousandFilterableRequestsOnce(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $schema = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $base = $container->get(AppFixtures::class);
        $performance = $container->get(PerformanceFixtures::class);
        self::assertInstanceOf(AppFixtures::class, $base);
        self::assertInstanceOf(PerformanceFixtures::class, $performance);

        $base->load($entityManager);
        $performance->load($entityManager);
        $performance->load($entityManager);

        $count = (int) $entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM service_request WHERE public_id LIKE '01KPERF000%'",
        );
        self::assertSame(10000, $count);
        self::assertSame(['performance'], PerformanceFixtures::getGroups());

        $requests = $container->get(ServiceRequestRepository::class);
        self::assertInstanceOf(ServiceRequestRepository::class, $requests);
        self::assertSame(2001, $requests->countFiltered(status: RequestStatus::InProgress));
        self::assertCount(25, $requests->findFilteredPage(status: RequestStatus::InProgress));
        self::assertSame(10004, $entityManager->getRepository(ServiceRequest::class)->count([]));
    }
}
