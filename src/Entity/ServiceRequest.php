<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestPriority;
use App\Enum\RequestStatus;
use App\Enum\UserRole;
use App\Repository\ServiceRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ServiceRequestRepository::class)]
#[ORM\Table(name: 'service_request')]
#[ORM\Index(name: 'idx_request_client_status_created', columns: ['client_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_request_assignee_status_created', columns: ['assignee_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_request_open_due', columns: ['due_at', 'priority'], options: ['where' => "status NOT IN ('closed', 'resolved')"])]
#[ORM\Index(name: 'idx_request_team_queue', columns: ['status', 'priority', 'created_at'])]
#[ORM\Index(name: 'idx_request_created_by_credential', columns: ['created_by_credential_id'])]
class ServiceRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26, unique: true, updatable: false, options: ['fixed' => true])]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $requester;

    #[ORM\ManyToOne(targetEntity: ApiCredential::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ApiCredential $createdByCredential;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $assignee = null;

    #[ORM\Column(length: 160)]
    #[Assert\Length(min: 3, max: 160)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\Length(min: 10, max: 10000)]
    private string $description;

    #[ORM\Column(length: 20, enumType: RequestPriority::class, options: ['default' => 'normal'])]
    private RequestPriority $priority;

    #[ORM\Column(length: 24, enumType: RequestStatus::class, options: ['default' => 'new'])]
    private RequestStatus $status = RequestStatus::New;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Client $client,
        ?User $requester,
        string $title,
        string $description,
        RequestPriority $priority = RequestPriority::Normal,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
        ?ApiCredential $createdByCredential = null,
    ) {
        if (($requester === null) === ($createdByCredential === null)) {
            throw new \InvalidArgumentException('A request requires exactly one requester origin.');
        }
        if ($client->isArchived()) {
            throw new \DomainException('Archived clients cannot receive requests.');
        }
        if ($requester !== null && !$requester->isActive()) {
            throw new \DomainException('Inactive users cannot create requests.');
        }
        if ($requester?->getRole() === UserRole::Client && $requester->getClient() !== $client) {
            throw new \DomainException('Client requesters may create requests only for their own client.');
        }
        if ($createdByCredential !== null && (!$createdByCredential->isActive() || $createdByCredential->getClient() !== $client)) {
            throw new \DomainException('API credentials may create requests only for their active client.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->client = $client;
        $this->requester = $requester;
        $this->createdByCredential = $createdByCredential;
        $this->priority = $priority;
        $this->createdAt = $createdAt ?? self::now();
        $this->updatedAt = $this->createdAt;
        $this->updateDetails($title, $description, false);
    }

    public static function fromApi(
        ApiCredential $credential,
        string $title,
        string $description,
        RequestPriority $priority = RequestPriority::Normal,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            client: $credential->getClient(),
            requester: null,
            title: $title,
            description: $description,
            priority: $priority,
            publicId: $publicId,
            createdAt: $createdAt,
            createdByCredential: $credential,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function getCreatedByCredential(): ?ApiCredential
    {
        return $this->createdByCredential;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function assignTo(?User $assignee, ?\DateTimeImmutable $at = null): void
    {
        if ($this->client->isArchived()) {
            throw new \DomainException('Archived client requests cannot be assigned.');
        }
        if ($assignee !== null && !$assignee->canManageWork()) {
            throw new \DomainException('Only active administrators or agents can be assigned.');
        }

        $this->assignee = $assignee;
        $this->touch($at);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function updateDetails(
        string $title,
        string $description,
        bool $touch = true,
        ?\DateTimeImmutable $at = null,
    ): void
    {
        $title = trim($title);
        $description = trim($description);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) {
            throw new \InvalidArgumentException('Request title must contain 3 to 160 characters.');
        }
        if (mb_strlen($description) < 10 || mb_strlen($description) > 10000) {
            throw new \InvalidArgumentException('Request description must contain 10 to 10,000 characters.');
        }

        $this->title = $title;
        $this->description = $description;
        if ($touch) {
            $this->touch($at);
        }
    }

    public function getPriority(): RequestPriority
    {
        return $this->priority;
    }

    public function changePriority(RequestPriority $priority, ?\DateTimeImmutable $at = null): void
    {
        $this->priority = $priority;
        $this->touch($at);
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function transitionTo(RequestStatus $target, ?\DateTimeImmutable $at = null): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new \DomainException(sprintf('Transition from %s to %s is not allowed.', $this->status->value, $target->value));
        }

        $at = ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'));
        if ($target === RequestStatus::Resolved) {
            $this->resolvedAt = $at;
            $this->closedAt = null;
        } elseif ($target === RequestStatus::Closed) {
            $this->closedAt = $at;
        } elseif ($target === RequestStatus::InProgress && $this->status->isTerminal()) {
            $this->resolvedAt = null;
            $this->closedAt = null;
        }

        $this->status = $target;
        $this->updatedAt = $at;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function scheduleFor(?\DateTimeImmutable $dueAt, ?\DateTimeImmutable $at = null): void
    {
        $this->dueAt = $dueAt?->setTimezone(new \DateTimeZone('UTC'));
        $this->touch($at);
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOverdue(?\DateTimeImmutable $at = null): bool
    {
        return !$this->status->isTerminal()
            && $this->dueAt !== null
            && $this->dueAt < ($at ?? self::now());
    }

    private function touch(?\DateTimeImmutable $at = null): void
    {
        $this->updatedAt = ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
