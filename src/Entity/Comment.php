<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_request_created', columns: ['service_request_id', 'created_at'])]
class Comment
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

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\Length(min: 1, max: 5000)]
    private string $body;

    #[ORM\Column(options: ['default' => false])]
    private bool $isInternal;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ServiceRequest $serviceRequest,
        User $author,
        string $body,
        bool $isInternal = false,
        ?string $publicId = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if (!$author->isActive()) {
            throw new \DomainException('Inactive users cannot add comments.');
        }
        if ($author->getRole() === UserRole::Client && $author->getClient() !== $serviceRequest->getClient()) {
            throw new \DomainException('Client users may comment only on their own requests.');
        }

        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new \InvalidArgumentException('Comment body must contain 1 to 5,000 characters.');
        }

        $this->publicId = (string) new Ulid($publicId);
        $this->serviceRequest = $serviceRequest;
        $this->author = $author;
        $this->body = $body;
        $this->isInternal = $author->getRole() === UserRole::Client ? false : $isInternal;
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

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isInternal(): bool
    {
        return $this->isInternal;
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
