<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\ApiCredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ApiCredentialRepository::class)]
#[ORM\Table(name: 'api_credential')]
#[ORM\Index(name: 'idx_credential_client_active', columns: ['client_id', 'revoked_at'])]
class ApiCredential
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

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 20, unique: true, updatable: false)]
    private string $selector;

    #[ORM\Column(length: 24, updatable: false)]
    private string $tokenPrefix;

    #[ORM\Column(length: 64, updatable: false, options: ['fixed' => true])]
    private string $secretHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Client $client,
        string $name,
        string $selector,
        string $tokenPrefix,
        string $secretHash,
        User $createdBy,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($client->isArchived()) {
            throw new \DomainException('Archived clients cannot receive API credentials.');
        }
        if (!$createdBy->isActive() || $createdBy->getRole() !== UserRole::Admin) {
            throw new \DomainException('Only an active administrator may create API credentials.');
        }

        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Credential name must contain 2 to 100 characters.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{4,20}$/', $selector)) {
            throw new \InvalidArgumentException('Credential selector must be 4 to 20 URL-safe characters.');
        }
        if ($tokenPrefix === '' || strlen($tokenPrefix) > 24) {
            throw new \InvalidArgumentException('Credential display prefix must contain at most 24 characters.');
        }
        $secretHash = strtolower($secretHash);
        if (!preg_match('/^[a-f0-9]{64}$/', $secretHash)) {
            throw new \InvalidArgumentException('Credential secret hash must be a SHA-256 digest.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->client = $client;
        $this->name = $name;
        $this->selector = $selector;
        $this->tokenPrefix = $tokenPrefix;
        $this->secretHash = $secretHash;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(?\DateTimeImmutable $at = null): bool
    {
        $at ??= self::now();
        if ($this->lastUsedAt !== null && $this->lastUsedAt > $at->modify('-1 hour')) {
            return false;
        }

        $this->lastUsedAt = $at;

        return true;
    }

    public function revoke(?\DateTimeImmutable $at = null): void
    {
        $this->revokedAt ??= $at ?? self::now();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null && !$this->client->isArchived();
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
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
