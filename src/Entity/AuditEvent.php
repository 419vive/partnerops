<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditActorType;
use App\Repository\AuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Table(name: 'audit_event')]
#[ORM\Index(name: 'idx_audit_client_occurred', columns: ['client_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_action_occurred', columns: ['action', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_subject_occurred', columns: ['subject_type', 'subject_public_id', 'occurred_at'])]
class AuditEvent
{
    private const FORBIDDEN_METADATA_KEY_PARTS = [
        'password', 'token', 'secret', 'authorization', 'header', 'cookie',
        'email', 'description', 'comment', 'body', 'request_payload',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26, unique: true, updatable: false, options: ['fixed' => true])]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Client $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $actor;

    #[ORM\Column(length: 24, enumType: AuditActorType::class, updatable: false)]
    private AuditActorType $actorType;

    #[ORM\Column(length: 80, updatable: false)]
    private string $action;

    #[ORM\Column(length: 60, updatable: false)]
    private string $subjectType;

    #[ORM\Column(length: 26, nullable: true, updatable: false, options: ['fixed' => true])]
    private ?string $subjectPublicId;

    /** @var array<string, bool|float|int|string|null>|\stdClass */
    #[ORM\Column(type: Types::JSONB, updatable: false)]
    private array|\stdClass $metadata;

    #[ORM\Column(length: 64, updatable: false)]
    private string $traceId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, bool|float|int|string|null> $metadata
     */
    public function __construct(
        string $action,
        string $subjectType,
        string $traceId,
        AuditActorType $actorType,
        ?Client $client = null,
        ?User $actor = null,
        ?string $subjectPublicId = null,
        array $metadata = [],
        ?string $publicId = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        if (($actorType === AuditActorType::User) !== ($actor !== null)) {
            throw new \InvalidArgumentException('User audit actors require a user, and non-user actors must not store one.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.]{1,79}$/', $action)) {
            throw new \InvalidArgumentException('Audit action must be a stable lowercase identifier.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/', $subjectType)) {
            throw new \InvalidArgumentException('Audit subject type must be a stable lowercase identifier.');
        }
        if ($traceId === '' || strlen($traceId) > 64 || preg_match('/[^A-Za-z0-9_.:-]/', $traceId)) {
            throw new \InvalidArgumentException('Trace ID contains unsupported characters.');
        }
        if ($subjectPublicId !== null && !Ulid::isValid($subjectPublicId)) {
            throw new \InvalidArgumentException('Audit subject public ID must be a ULID.');
        }
        self::validateMetadata($metadata);

        $this->publicId = (string) new Ulid($publicId);
        $this->client = $client;
        $this->actor = $actor;
        $this->actorType = $actorType;
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectPublicId = $subjectPublicId;
        $this->metadata = $metadata === [] ? new \stdClass() : $metadata;
        $this->traceId = $traceId;
        $this->occurredAt = $occurredAt ?? self::now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorType(): AuditActorType
    {
        return $this->actorType;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectPublicId(): ?string
    {
        return $this->subjectPublicId;
    }

    /** @return array<string, bool|float|int|string|null> */
    public function getMetadata(): array
    {
        return $this->metadata instanceof \stdClass ? [] : $this->metadata;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private static function validateMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                throw new \InvalidArgumentException('Audit metadata keys must be stable lowercase identifiers.');
            }
            foreach (self::FORBIDDEN_METADATA_KEY_PARTS as $forbidden) {
                if (str_contains($key, $forbidden)) {
                    throw new \InvalidArgumentException(sprintf('Audit metadata key "%s" is sensitive.', $key));
                }
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Audit metadata values must be scalar or null.');
            }
        }
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
