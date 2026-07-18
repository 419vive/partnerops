<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'client')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 26, unique: true, updatable: false, options: ['fixed' => true])]
    private string $publicId;

    #[ORM\Column(length: 120)]
    #[Assert\Length(min: 2, max: 120)]
    private string $name;

    #[ORM\Column(length: 80)]
    #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    private string $slug;

    #[ORM\Column(options: ['default' => false])]
    private bool $isArchived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $slug,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->publicId = (string) new Ulid($publicId);
        $this->createdAt = $createdAt ?? self::now();
        $this->updatedAt = $this->createdAt;
        $this->rename($name, false);
        $this->changeSlug($slug, false);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name, bool $touch = true): void
    {
        $name = trim($name);
        $length = mb_strlen($name);
        if ($length < 2 || $length > 120) {
            throw new \InvalidArgumentException('Client name must contain 2 to 120 characters.');
        }

        $this->name = $name;
        if ($touch) {
            $this->touch();
        }
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function changeSlug(string $slug, bool $touch = true): void
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
            throw new \InvalidArgumentException('Client slug must use lowercase letters, numbers, and single hyphens.');
        }

        $this->slug = $slug;
        if ($touch) {
            $this->touch();
        }
    }

    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function archive(?\DateTimeImmutable $at = null): void
    {
        if ($this->isArchived) {
            return;
        }

        $this->isArchived = true;
        $this->archivedAt = $at ?? self::now();
        $this->updatedAt = $this->archivedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
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
