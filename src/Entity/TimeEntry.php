<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ApprovalStatus;
use App\Repository\TimeEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(name: 'idx_time_allowance_approval', columns: ['allowance_period_id', 'approval_status'])]
#[ORM\Index(name: 'idx_time_request_created', columns: ['service_request_id', 'created_at'])]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26, unique: true, updatable: false, options: ['fixed' => true])]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: ServiceRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ServiceRequest $serviceRequest;

    #[ORM\ManyToOne(targetEntity: AllowancePeriod::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private AllowancePeriod $allowancePeriod;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(updatable: false)]
    private int $minutes;

    #[ORM\Column(length: 500, updatable: false)]
    #[Assert\Length(min: 3, max: 500)]
    private string $description;

    #[ORM\Column(options: ['default' => true])]
    private bool $isClientVisible;

    #[ORM\Column(length: 20, enumType: ApprovalStatus::class, options: ['default' => 'approved'])]
    private ApprovalStatus $approvalStatus;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $workDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ServiceRequest $serviceRequest,
        AllowancePeriod $allowancePeriod,
        User $author,
        int $minutes,
        string $description,
        \DateTimeImmutable $workDate,
        bool $isClientVisible = true,
        ApprovalStatus $approvalStatus = ApprovalStatus::Approved,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($serviceRequest->getClient()->isArchived()) {
            throw new \DomainException('Archived clients cannot receive time entries.');
        }
        if ($serviceRequest->getClient() !== $allowancePeriod->getClient()) {
            throw new \DomainException('The allowance and request must belong to the same client.');
        }
        if (!$allowancePeriod->contains($workDate)) {
            throw new \DomainException('Work date is outside the selected allowance period.');
        }
        if (!$author->canManageWork()) {
            throw new \DomainException('Only active administrators or agents may log time.');
        }
        if ($minutes < 1 || $minutes > 1440) {
            throw new \InvalidArgumentException('Minutes must be between 1 and 1,440.');
        }

        $description = trim($description);
        if (mb_strlen($description) < 3 || mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Time entry description must contain 3 to 500 characters.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->serviceRequest = $serviceRequest;
        $this->allowancePeriod = $allowancePeriod;
        $this->author = $author;
        $this->minutes = $minutes;
        $this->description = $description;
        $this->workDate = new \DateTimeImmutable($workDate->format('Y-m-d'), new \DateTimeZone('UTC'));
        $this->isClientVisible = $isClientVisible;
        $this->approvalStatus = $approvalStatus;
        $this->createdAt = $createdAt ?? self::now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getServiceRequest(): ServiceRequest
    {
        return $this->serviceRequest;
    }

    public function getAllowancePeriod(): AllowancePeriod
    {
        return $this->allowancePeriod;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isClientVisible(): bool
    {
        return $this->isClientVisible;
    }

    public function getApprovalStatus(): ApprovalStatus
    {
        return $this->approvalStatus;
    }

    public function getWorkDate(): \DateTimeImmutable
    {
        return $this->workDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
