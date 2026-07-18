<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\AllowancePeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: AllowancePeriodRepository::class)]
#[ORM\Table(name: 'allowance_period')]
#[ORM\Index(name: 'idx_allowance_client_dates', columns: ['client_id', 'starts_on', 'ends_on'])]
class AllowancePeriod
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

    #[ORM\Column(type: Types::DATE_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(updatable: false)]
    private int $includedMinutes;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Client $client,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        int $includedMinutes,
        User $createdBy,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $startsOn = self::calendarDate($startsOn);
        $endsOn = self::calendarDate($endsOn);
        if ($client->isArchived()) {
            throw new \DomainException('Archived clients cannot receive allowance periods.');
        }
        if (!$createdBy->isActive() || $createdBy->getRole() !== UserRole::Admin) {
            throw new \DomainException('Only an active administrator may create an allowance period.');
        }
        if ($endsOn < $startsOn) {
            throw new \InvalidArgumentException('Allowance end date must be on or after its start date.');
        }
        if ($includedMinutes < 1 || $includedMinutes > 1000000) {
            throw new \InvalidArgumentException('Included minutes must be between 1 and 1,000,000.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->client = $client;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->includedMinutes = $includedMinutes;
        $this->createdBy = $createdBy;
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

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getIncludedMinutes(): int
    {
        return $this->includedMinutes;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        $date = self::calendarDate($date);

        return $date >= $this->startsOn && $date <= $this->endsOn;
    }

    public function overlaps(\DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn): bool
    {
        return self::calendarDate($startsOn) <= $this->endsOn
            && self::calendarDate($endsOn) >= $this->startsOn;
    }

    private static function calendarDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
