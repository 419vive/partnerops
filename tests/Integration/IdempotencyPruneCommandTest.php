<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ApiCredential;
use App\Entity\Client;
use App\Entity\IdempotencyRecord;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IdempotencyPruneCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->entityManager);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        unset($this->entityManager);
        parent::tearDown();
    }

    public function testCommandDeletesOnlyExpiredRecordsWithinConfiguredBounds(): void
    {
        $client = new Client('Cleanup Client', 'cleanup-client');
        $admin = new User('cleanup-admin@example.test', 'not-used', 'Cleanup Admin', UserRole::Admin);
        $credential = new ApiCredential(
            $client,
            'Cleanup API',
            'cleanup1',
            'ptk_cleanup',
            str_repeat('a', 64),
            $admin,
        );
        $request = ServiceRequest::fromApi(
            $credential,
            'Cleanup request',
            'A valid request used to test bounded idempotency cleanup.',
        );
        foreach ([$client, $admin, $credential, $request] as $entity) {
            $this->entityManager->persist($entity);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        for ($index = 0; $index < 3; ++$index) {
            $this->entityManager->persist(new IdempotencyRecord(
                $credential,
                'expired-key-'.$index,
                hash('sha256', 'expired-'.$index),
                $request,
                ['id' => $request->getPublicId()],
                $now->modify('-2 days'),
                $now->modify('-1 day'),
            ));
        }
        $this->entityManager->persist(new IdempotencyRecord(
            $credential,
            'active-key',
            hash('sha256', 'active'),
            $request,
            ['id' => $request->getPublicId()],
            $now,
            $now->modify('+1 day'),
        ));
        $this->entityManager->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:idempotency:prune');
        self::assertSame('app:idempotency:prune', $command->getName());
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--batch-size' => '1',
            '--max-batches' => '2',
        ]));
        self::assertStringContainsString('Pruned 2 expired', $tester->getDisplay());
        self::assertStringContainsString('2 batch(es)', $tester->getDisplay());
        self::assertSame(2, $this->entityManager->getRepository(IdempotencyRecord::class)->count([]));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--batch-size' => '2',
            '--max-batches' => '1',
        ]));
        self::assertStringContainsString('Pruned 1 expired', $tester->getDisplay());
        self::assertSame(1, $this->entityManager->getRepository(IdempotencyRecord::class)->count([]));
        self::assertNotNull($this->entityManager->getRepository(IdempotencyRecord::class)->findOneBy([
            'idempotencyKey' => 'active-key',
        ]));

        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => '0']));
        self::assertStringContainsString('--batch-size must be an integer between 1 and 10000', $tester->getDisplay());
    }
}
