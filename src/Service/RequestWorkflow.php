<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Enum\RequestStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class RequestWorkflow
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function transition(
        ServiceRequest $request,
        RequestStatus $target,
        User $actor,
        string $traceId,
        ?int $expectedVersion = null,
        ?\DateTimeImmutable $at = null,
    ): AuditEvent {
        if (!$actor->canManageWork()) {
            throw new \DomainException('Only an active administrator or agent may transition requests.');
        }
        if ($request->getClient()->isArchived()) {
            throw new \DomainException('Archived client requests cannot be changed.');
        }
        if ($expectedVersion !== null && $expectedVersion < 1) {
            throw new \InvalidArgumentException('Expected version must be a positive integer.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $request,
            $target,
            $actor,
            $traceId,
            $expectedVersion,
            $at,
        ): AuditEvent {
            if ($expectedVersion !== null) {
                $entityManager->lock($request, LockMode::OPTIMISTIC, $expectedVersion);
            }

            $from = $request->getStatus();
            $request->transitionTo($target, $at);
            $event = new AuditEvent(
                action: 'request.status_changed',
                subjectType: 'service_request',
                traceId: $traceId,
                actorType: AuditActorType::User,
                client: $request->getClient(),
                actor: $actor,
                subjectPublicId: $request->getPublicId(),
                metadata: ['from_status' => $from->value, 'to_status' => $target->value],
                occurredAt: $at,
            );
            $entityManager->persist($event);

            return $event;
        });
    }
}
