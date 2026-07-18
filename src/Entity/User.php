<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26, unique: true, updatable: false, options: ['fixed' => true])]
    private string $publicId;

    /** @var non-empty-string */
    #[ORM\Column(length: 180)]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 100)]
    #[Assert\Length(min: 2, max: 100)]
    private string $displayName;

    #[ORM\Column(length: 20, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Client $client;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $email,
        string $passwordHash,
        string $displayName,
        UserRole $role,
        ?Client $client = null,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if (($role === UserRole::Client) !== ($client !== null)) {
            throw new \InvalidArgumentException('Client users require a client; team users cannot belong to one.');
        }
        if ($client?->isArchived()) {
            throw new \DomainException('Archived clients cannot receive users.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->role = $role;
        $this->client = $client;
        $this->createdAt = $createdAt ?? self::now();
        $this->updatedAt = $this->createdAt;
        $this->changeEmail($email, false);
        $this->changePasswordHash($passwordHash, false);
        $this->changeDisplayName($displayName, false);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function changeEmail(string $email, bool $touch = true): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 180 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $this->email = $email;
        if ($touch) {
            $this->touch();
        }
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->securityRole(), 'ROLE_USER'];
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function changePasswordHash(string $passwordHash, bool $touch = true): void
    {
        if (trim($passwordHash) === '' || strlen($passwordHash) > 255) {
            throw new \InvalidArgumentException('A password hash is required.');
        }

        $this->passwordHash = $passwordHash;
        if ($touch) {
            $this->touch();
        }
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && hash_equals($this->publicId, $user->publicId)
            && hash_equals($this->email, $user->email)
            && hash_equals($this->passwordHash, $user->passwordHash)
            && $this->role === $user->role
            && $this->isActive === $user->isActive
            && $this->client?->getPublicId() === $user->client?->getPublicId()
            && $this->client?->isArchived() === $user->client?->isArchived();
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function changeDisplayName(string $displayName, bool $touch = true): void
    {
        $displayName = trim($displayName);
        $length = mb_strlen($displayName);
        if ($length < 2 || $length > 100) {
            throw new \InvalidArgumentException('Display name must contain 2 to 100 characters.');
        }

        $this->displayName = $displayName;
        if ($touch) {
            $this->touch();
        }
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function canManageWork(): bool
    {
        return $this->isActive && $this->role->canManageWork();
    }

    public function deactivate(?\DateTimeImmutable $at = null): void
    {
        if (!$this->isActive) {
            return;
        }

        $this->isActive = false;
        $this->deactivatedAt = $at ?? self::now();
        $this->updatedAt = $this->deactivatedAt;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = self::now();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
