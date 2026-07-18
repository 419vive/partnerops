<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AllowancePeriod;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\UserRole;
use App\Repository\AllowancePeriodRepository;
use App\Repository\CommentRepository;
use App\Repository\ServiceRequestRepository;
use App\Repository\TimeEntryRepository;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryQueryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private BacktraceDebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->queries = self::getContainer()->get(BacktraceDebugDataHolder::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->entityManager);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        unset($this->entityManager, $this->queries);
        parent::tearDown();
    }

    public function testRequestListsFetchDisplayedAssociationsInOneQuery(): void
    {
        [$client, $agent, $requester] = $this->actors();
        for ($i = 0; $i < 3; ++$i) {
            $request = new ServiceRequest(
                $client,
                $requester,
                sprintf('Request %d', $i),
                'A description long enough for validation.',
                RequestPriority::Normal,
            );
            $request->assignTo($agent);
            $this->entityManager->persist($request);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        /** @var ServiceRequestRepository $repository */
        $repository = $this->entityManager->getRepository(ServiceRequest::class);

        $this->queries->reset();
        $page = $repository->findFilteredPage();
        foreach ($page as $request) {
            $request->getClient()->getName();
            $request->getAssignee()?->getDisplayName();
        }
        self::assertCount(1, $this->queries->getData()['default'] ?? []);

        $this->entityManager->clear();
        $this->queries->reset();
        $queue = $repository->findPrioritizedQueue();
        foreach ($queue as $request) {
            $request->getClient()->getName();
            $request->getAssignee()?->getDisplayName();
        }
        self::assertCount(1, $this->queries->getData()['default'] ?? []);
    }

    public function testTeamViewsExcludeArchivedClientRequestsWhileExplicitClientHistoryRemainsReadable(): void
    {
        [$activeClient, $agent, $activeRequester] = $this->actors();
        $archivedClient = new Client('Archived Client', 'archived');
        $archivedRequester = new User(
            'archived@example.test',
            'not-used',
            'Archived User',
            UserRole::Client,
            $archivedClient,
        );
        $activeRequest = new ServiceRequest(
            $activeClient,
            $activeRequester,
            'Active client request',
            'A description long enough for validation.',
        );
        $archivedRequest = new ServiceRequest(
            $archivedClient,
            $archivedRequester,
            'Archived client request',
            'This request must remain available as historical evidence.',
            RequestPriority::Urgent,
        );
        $now = new \DateTimeImmutable('2026-07-18 08:00:00', new \DateTimeZone('UTC'));
        $archivedRequest->scheduleFor($now->modify('-1 day'));
        $admin = new User('admin@example.test', 'not-used', 'Admin User', UserRole::Admin);
        $startsOn = new \DateTimeImmutable('2026-07-01', new \DateTimeZone('UTC'));
        $endsOn = new \DateTimeImmutable('2026-07-31', new \DateTimeZone('UTC'));
        $activeAllowance = new AllowancePeriod($activeClient, $startsOn, $endsOn, 1, $admin);
        $archivedAllowance = new AllowancePeriod($archivedClient, $startsOn, $endsOn, 1, $admin);
        $activeEntry = new TimeEntry($activeRequest, $activeAllowance, $agent, 2, 'Active work', $now);
        $archivedEntry = new TimeEntry($archivedRequest, $archivedAllowance, $agent, 2, 'Archived work', $now);

        foreach ([
            $archivedClient,
            $archivedRequester,
            $admin,
            $activeRequest,
            $archivedRequest,
            $activeAllowance,
            $archivedAllowance,
            $activeEntry,
            $archivedEntry,
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        // Archiving does not mutate historical requests, but it removes them from
        // every unscoped team operations view.
        $archivedClient->archive($now);
        $this->entityManager->flush();
        $this->entityManager->clear();

        /** @var ServiceRequestRepository $repository */
        $repository = $this->entityManager->getRepository(ServiceRequest::class);
        /** @var AllowancePeriodRepository $allowances */
        $allowances = $this->entityManager->getRepository(AllowancePeriod::class);
        $archivedClient = $this->entityManager->getRepository(Client::class)->findOneBy(['slug' => 'archived']);
        self::assertInstanceOf(Client::class, $archivedClient);

        self::assertSame(1, $repository->countFiltered());
        self::assertSame(['Active client request'], array_map(
            static fn (ServiceRequest $request): string => $request->getTitle(),
            $repository->findFilteredPage(),
        ));
        self::assertSame(['Active client request'], array_map(
            static fn (ServiceRequest $request): string => $request->getTitle(),
            $repository->findPrioritizedQueue(),
        ));
        self::assertSame(
            ['open' => 1, 'overdue' => 0, 'dueSoon' => 0, 'unassigned' => 1],
            $repository->dashboardCounts($now, $now->modify('+7 days')),
        );
        self::assertSame(1, $allowances->countCurrentOverBudget($now));

        self::assertSame(1, $repository->countFiltered($archivedClient));
        self::assertSame(['Archived client request'], array_map(
            static fn (ServiceRequest $request): string => $request->getTitle(),
            $repository->findFilteredPage(client: $archivedClient),
        ));
        self::assertCount(1, $repository->findPrioritizedQueue(client: $archivedClient));
        self::assertSame(
            ['open' => 1, 'overdue' => 1, 'dueSoon' => 0, 'unassigned' => 1],
            $repository->dashboardCounts($now, $now->modify('+7 days'), $archivedClient),
        );
        self::assertSame(1, $allowances->countCurrentOverBudget($now, $archivedClient));
        self::assertInstanceOf(
            ServiceRequest::class,
            $repository->findOneForClientByPublicId($archivedClient, $archivedRequest->getPublicId()),
        );
    }

    public function testClientVisibleCommentHistoryIsPaginatedWithoutLosingOlderRowsAndFetchesAuthorsInOneQuery(): void
    {
        [$client, $agent, $requester] = $this->actors();
        $request = new ServiceRequest(
            $client,
            $requester,
            'Long-running request',
            'A description long enough for validation.',
        );
        $this->entityManager->persist($request);

        $start = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        for ($i = 0; $i < CommentRepository::PAGE_SIZE + 5; ++$i) {
            $this->entityManager->persist(new Comment(
                $request,
                $i % 2 === 0 ? $requester : $agent,
                sprintf('Comment %03d', $i),
                createdAt: $start->modify(sprintf('+%d seconds', $i)),
            ));
        }
        $this->entityManager->persist(new Comment(
            $request,
            $agent,
            'Internal comment',
            true,
            createdAt: $start->modify('+1000 seconds'),
        ));
        $publicId = $request->getPublicId();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $request = $this->entityManager->getRepository(ServiceRequest::class)->findOneBy(['publicId' => $publicId]);
        self::assertInstanceOf(ServiceRequest::class, $request);
        /** @var CommentRepository $repository */
        $repository = $this->entityManager->getRepository(Comment::class);

        $this->queries->reset();
        $comments = $repository->findClientVisible($request->getClient(), $request);
        foreach ($comments as $comment) {
            $comment->getAuthor()->getDisplayName();
        }

        self::assertCount(CommentRepository::PAGE_SIZE, $comments);
        self::assertSame('Comment 005', $comments[0]->getBody());
        self::assertSame('Comment 054', $comments[array_key_last($comments)]->getBody());
        self::assertCount(1, $this->queries->getData()['default'] ?? []);

        $older = $repository->findClientVisible($request->getClient(), $request, 2);
        self::assertCount(5, $older);
        self::assertSame('Comment 000', $older[0]->getBody());
        self::assertSame('Comment 004', $older[array_key_last($older)]->getBody());
        self::assertSame(CommentRepository::PAGE_SIZE + 5, $repository->countClientVisible($request->getClient(), $request));
    }

    public function testTimeEntryHistoryIsPaginatedAndFetchesAuthorsInOneQuery(): void
    {
        [$client, $agent, $requester] = $this->actors();
        $admin = new User('admin@example.test', 'not-used', 'Admin User', UserRole::Admin);
        $request = new ServiceRequest(
            $client,
            $requester,
            'Long-running request',
            'A description long enough for validation.',
        );
        $period = new AllowancePeriod(
            $client,
            new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-12-31', new \DateTimeZone('UTC')),
            100000,
            $admin,
        );
        $this->entityManager->persist($admin);
        $this->entityManager->persist($request);
        $this->entityManager->persist($period);

        $start = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        for ($i = 0; $i < TimeEntryRepository::PAGE_SIZE + 5; ++$i) {
            $this->entityManager->persist(new TimeEntry(
                $request,
                $period,
                $agent,
                1,
                sprintf('Entry %03d', $i),
                new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')),
                createdAt: $start->modify(sprintf('+%d seconds', $i)),
            ));
        }
        $publicId = $request->getPublicId();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $request = $this->entityManager->getRepository(ServiceRequest::class)->findOneBy(['publicId' => $publicId]);
        self::assertInstanceOf(ServiceRequest::class, $request);
        /** @var TimeEntryRepository $repository */
        $repository = $this->entityManager->getRepository(TimeEntry::class);

        $this->queries->reset();
        $entries = $repository->findRecentForRequest($request, false);
        foreach ($entries as $entry) {
            $entry->getAuthor()->getDisplayName();
        }

        self::assertCount(TimeEntryRepository::PAGE_SIZE, $entries);
        self::assertSame('Entry 054', $entries[0]->getDescription());
        self::assertSame('Entry 005', $entries[array_key_last($entries)]->getDescription());
        self::assertCount(1, $this->queries->getData()['default'] ?? []);

        $older = $repository->findRecentForRequest($request, false, 2);
        self::assertCount(5, $older);
        self::assertSame('Entry 004', $older[0]->getDescription());
        self::assertSame('Entry 000', $older[array_key_last($older)]->getDescription());
        self::assertSame(TimeEntryRepository::PAGE_SIZE + 5, $repository->countForRequest($request, false));
    }

    /** @return array{Client, User, User} */
    private function actors(): array
    {
        $client = new Client('Acme Client', 'acme');
        $agent = new User('agent@example.test', 'not-used', 'Agent User', UserRole::Agent);
        $requester = new User('client@example.test', 'not-used', 'Client User', UserRole::Client, $client);
        foreach ([$client, $agent, $requester] as $entity) {
            $this->entityManager->persist($entity);
        }

        return [$client, $agent, $requester];
    }
}
