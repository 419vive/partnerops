<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiCredential;
use App\Entity\AuditEvent;
use App\Entity\IdempotencyRecord;
use App\Entity\ServiceRequest;
use App\Enum\AuditActorType;
use App\Enum\RequestPriority;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiRequestCreator
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private ApiRequestPresenter $presenter,
        private TraceIdProvider $traceIds,
    ) {
    }

    /**
     * @param array{title:string, description:string, priority:RequestPriority, dueAt:?\DateTimeImmutable} $input
     * @return array{status:int, body:array<string, mixed>, replayed:bool}
     */
    public function create(ApiCredential $credential, string $idempotencyKey, array $input): array
    {
        $fingerprint = $this->fingerprint($input);
        $manager = $this->doctrine->getManagerForClass(ServiceRequest::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('The API requires Doctrine ORM.');
        }

        $connection = $manager->getConnection();
        $connection->beginTransaction();

        try {
            $credential = $this->revalidateCredential($manager, $credential);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $existing = $manager->getRepository(IdempotencyRecord::class)->findOneBy([
                'apiCredential' => $credential,
                'idempotencyKey' => $idempotencyKey,
            ]);
            if ($existing instanceof IdempotencyRecord && !$existing->isExpired($now)) {
                $result = $this->replay($existing, $fingerprint);
                $connection->commit();

                return $result;
            }
            if ($existing instanceof IdempotencyRecord) {
                $manager->remove($existing);
                $manager->flush();
            }

            $serviceRequest = ServiceRequest::fromApi(
                $credential,
                $input['title'],
                $input['description'],
                $input['priority'],
            );
            if ($input['dueAt'] !== null) {
                $serviceRequest->scheduleFor($input['dueAt']);
            }

            $body = $this->presenter->present($serviceRequest, []);
            $audit = new AuditEvent(
                action: 'request.created',
                subjectType: 'service_request',
                traceId: $this->traceIds->current(),
                actorType: AuditActorType::ApiCredential,
                client: $credential->getClient(),
                subjectPublicId: $serviceRequest->getPublicId(),
                metadata: ['priority' => $serviceRequest->getPriority()->value],
            );
            $record = new IdempotencyRecord(
                $credential,
                $idempotencyKey,
                $fingerprint,
                $serviceRequest,
                $body,
                $now,
                $now->modify('+24 hours'),
            );

            $manager->persist($serviceRequest);
            $manager->persist($audit);
            $manager->persist($record);
            $manager->flush();
            $connection->commit();

            return ['status' => Response::HTTP_CREATED, 'body' => $body, 'replayed' => false];
        } catch (UniqueConstraintViolationException $exception) {
            $this->rollback($manager);

            return $this->replayWinner(
                (int) $credential->getId(),
                $idempotencyKey,
                $fingerprint,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $exception,
            );
        } catch (\Throwable $exception) {
            $this->rollback($manager);
            throw $exception;
        }
    }

    /**
     * @param array{title:string, description:string, priority:RequestPriority, dueAt:?\DateTimeImmutable} $input
     */
    private function fingerprint(array $input): string
    {
        return hash('sha256', json_encode([
            'title' => $input['title'],
            'description' => $input['description'],
            'priority' => $input['priority']->value,
            'dueAt' => $input['dueAt']?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{status:int, body:array<string, mixed>, replayed:bool} */
    private function replay(IdempotencyRecord $record, string $fingerprint): array
    {
        if (!hash_equals($record->getRequestFingerprint(), $fingerprint)) {
            throw new ApiProblemException(
                Response::HTTP_CONFLICT,
                'idempotency_conflict',
                'Conflict',
                'This idempotency key was already used with a different request body.',
            );
        }

        return [
            'status' => $record->getResponseStatus(),
            'body' => $record->getResponseBody(),
            'replayed' => true,
        ];
    }

    /** @return array{status:int, body:array<string, mixed>, replayed:bool} */
    private function replayWinner(
        int $credentialId,
        string $idempotencyKey,
        string $fingerprint,
        \DateTimeImmutable $now,
        UniqueConstraintViolationException $exception,
    ): array {
        $manager = $this->doctrine->resetManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('The API requires Doctrine ORM.', previous: $exception);
        }

        $credential = $manager->find(ApiCredential::class, $credentialId);
        $record = $credential instanceof ApiCredential
            ? $manager->getRepository(IdempotencyRecord::class)->findOneBy([
                'apiCredential' => $credential,
                'idempotencyKey' => $idempotencyKey,
            ])
            : null;
        if (!$record instanceof IdempotencyRecord || $record->isExpired($now)) {
            throw new \RuntimeException('The idempotency winner could not be loaded.', previous: $exception);
        }

        return $this->replay($record, $fingerprint);
    }

    private function rollback(EntityManagerInterface $manager): void
    {
        if ($manager->getConnection()->isTransactionActive()) {
            $manager->getConnection()->rollBack();
        }
    }

    private function revalidateCredential(EntityManagerInterface $manager, ApiCredential $credential): ApiCredential
    {
        $credentialId = $credential->getId();
        if ($credentialId === null) {
            throw new ApiProblemException(
                Response::HTTP_UNAUTHORIZED,
                'unauthorized',
                'Unauthorized',
                'Valid authentication is required.',
            );
        }
        if (!$manager->contains($credential)) {
            $credential = $manager->find(ApiCredential::class, $credentialId);
        }
        if (!$credential instanceof ApiCredential) {
            throw new ApiProblemException(
                Response::HTTP_UNAUTHORIZED,
                'unauthorized',
                'Unauthorized',
                'Valid authentication is required.',
            );
        }

        $manager->refresh($credential, LockMode::PESSIMISTIC_READ);
        $manager->refresh($credential->getClient(), LockMode::PESSIMISTIC_READ);
        if (!$credential->isActive()) {
            throw new ApiProblemException(
                Response::HTTP_UNAUTHORIZED,
                'unauthorized',
                'Unauthorized',
                'Valid authentication is required.',
            );
        }

        return $credential;
    }
}
