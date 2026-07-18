<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Enum\RequestPriority;
use App\Enum\UserRole;
use App\Repository\AllowancePeriodRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class RequestOperations
{
    /** @var array<string, list<string>> */
    private const AUDIT_METADATA_KEYS = [
        'request.created' => ['priority'],
        'request.updated' => [
            'content_changed',
            'from_priority', 'to_priority',
            'from_assignee', 'to_assignee',
            'from_due_at', 'to_due_at',
        ],
        'comment.added' => ['is_internal'],
        'time_entry.created' => ['minutes', 'is_client_visible', 'work_date'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AllowancePeriodRepository $allowancePeriods,
    ) {
    }

    public function create(
        Client $client,
        User $actor,
        string $title,
        string $description,
        RequestPriority $priority,
        string $traceId,
    ): ServiceRequest {
        if (!$actor->isActive()) {
            throw new \DomainException('Inactive users cannot create requests.');
        }
        if ($actor->getRole() === UserRole::Client && $actor->getClient() !== $client) {
            throw new \DomainException('Client users may create requests only for their own client.');
        }

        $serviceRequest = new ServiceRequest($client, $actor, $title, $description, $priority);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $serviceRequest,
            $client,
            $actor,
            $priority,
            $traceId,
        ): ServiceRequest {
            $entityManager->persist($serviceRequest);
            $entityManager->persist($this->audit(
                action: 'request.created',
                subjectType: 'service_request',
                subjectPublicId: $serviceRequest->getPublicId(),
                client: $client,
                actor: $actor,
                traceId: $traceId,
                metadata: ['priority' => $priority->value],
            ));

            return $serviceRequest;
        });
    }

    public function update(
        ServiceRequest $serviceRequest,
        User $actor,
        string $title,
        string $description,
        RequestPriority $priority,
        ?User $assignee,
        ?\DateTimeImmutable $dueAt,
        int $expectedVersion,
        string $traceId,
    ): void {
        $this->assertTeamMutation($serviceRequest, $actor);
        if ($expectedVersion < 1) {
            throw new \InvalidArgumentException('Expected version must be a positive integer.');
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $serviceRequest,
            $actor,
            $title,
            $description,
            $priority,
            $assignee,
            $dueAt,
            $expectedVersion,
            $traceId,
        ): void {
            $entityManager->lock($serviceRequest, LockMode::OPTIMISTIC, $expectedVersion);

            $fromPriority = $serviceRequest->getPriority();
            $fromAssignee = $serviceRequest->getAssignee();
            $fromDueAt = $serviceRequest->getDueAt();
            $titleChanged = trim($title) !== $serviceRequest->getTitle();
            $descriptionChanged = trim($description) !== $serviceRequest->getDescription();
            $changedAt = self::now();

            $serviceRequest->updateDetails($title, $description, at: $changedAt);
            $serviceRequest->changePriority($priority, $changedAt);
            $serviceRequest->assignTo($assignee, $changedAt);
            $serviceRequest->scheduleFor($dueAt, $changedAt);

            $entityManager->persist($this->audit(
                action: 'request.updated',
                subjectType: 'service_request',
                subjectPublicId: $serviceRequest->getPublicId(),
                client: $serviceRequest->getClient(),
                actor: $actor,
                traceId: $traceId,
                metadata: [
                    'content_changed' => $titleChanged || $descriptionChanged,
                    'from_priority' => $fromPriority->value,
                    'to_priority' => $priority->value,
                    'from_assignee' => $fromAssignee?->getPublicId(),
                    'to_assignee' => $assignee?->getPublicId(),
                    'from_due_at' => self::formatInstant($fromDueAt),
                    'to_due_at' => self::formatInstant($dueAt),
                ],
                occurredAt: $changedAt,
            ));
        });
    }

    public function addComment(
        ServiceRequest $serviceRequest,
        User $actor,
        string $body,
        bool $isInternal,
        string $traceId,
    ): Comment {
        if ($serviceRequest->getClient()->isArchived()) {
            throw new \DomainException('Archived client requests cannot receive comments.');
        }
        if ($actor->getRole() === UserRole::Client && $actor->getClient() !== $serviceRequest->getClient()) {
            throw new \DomainException('Client users may comment only on their own requests.');
        }

        $comment = new Comment($serviceRequest, $actor, $body, $isInternal);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $serviceRequest,
            $actor,
            $comment,
            $traceId,
        ): Comment {
            $entityManager->persist($comment);
            $entityManager->persist($this->audit(
                action: 'comment.added',
                subjectType: 'service_request',
                subjectPublicId: $serviceRequest->getPublicId(),
                client: $serviceRequest->getClient(),
                actor: $actor,
                traceId: $traceId,
                metadata: ['is_internal' => $comment->isInternal()],
            ));

            return $comment;
        });
    }

    public function addTime(
        ServiceRequest $serviceRequest,
        User $actor,
        int $minutes,
        string $description,
        \DateTimeImmutable $workDate,
        bool $isClientVisible,
        string $traceId,
    ): TimeEntry {
        $this->assertTeamMutation($serviceRequest, $actor);
        $allowance = $this->allowancePeriods->findApplicable($serviceRequest->getClient(), $workDate);
        if ($allowance === null) {
            throw new \DomainException('No allowance covers the selected work date.');
        }
        $timeEntry = new TimeEntry(
            $serviceRequest,
            $allowance,
            $actor,
            $minutes,
            $description,
            $workDate,
            $isClientVisible,
        );

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $serviceRequest,
            $actor,
            $timeEntry,
            $minutes,
            $workDate,
            $isClientVisible,
            $traceId,
        ): TimeEntry {
            $entityManager->persist($timeEntry);
            $entityManager->persist($this->audit(
                action: 'time_entry.created',
                subjectType: 'service_request',
                subjectPublicId: $serviceRequest->getPublicId(),
                client: $serviceRequest->getClient(),
                actor: $actor,
                traceId: $traceId,
                metadata: [
                    'minutes' => $minutes,
                    'is_client_visible' => $isClientVisible,
                    'work_date' => $workDate->format('Y-m-d'),
                ],
            ));

            return $timeEntry;
        });
    }

    private function assertTeamMutation(ServiceRequest $serviceRequest, User $actor): void
    {
        if (!$actor->canManageWork()) {
            throw new \DomainException('Only an active administrator or agent may change requests.');
        }
        if ($serviceRequest->getClient()->isArchived()) {
            throw new \DomainException('Archived client requests cannot be changed.');
        }
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function audit(
        string $action,
        string $subjectType,
        string $subjectPublicId,
        Client $client,
        User $actor,
        string $traceId,
        array $metadata,
        ?\DateTimeImmutable $occurredAt = null,
    ): AuditEvent {
        $allowedKeys = self::AUDIT_METADATA_KEYS[$action] ?? null;
        if ($allowedKeys === null || array_diff(array_keys($metadata), $allowedKeys) !== []) {
            throw new \LogicException('Audit metadata is not allow-listed.');
        }

        return new AuditEvent(
            action: $action,
            subjectType: $subjectType,
            traceId: $traceId,
            actorType: AuditActorType::User,
            client: $client,
            actor: $actor,
            subjectPublicId: $subjectPublicId,
            metadata: $metadata,
            occurredAt: $occurredAt,
        );
    }

    private static function formatInstant(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
