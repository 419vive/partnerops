<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdempotencyRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IdempotencyRecordRepository::class)]
#[ORM\Table(name: 'idempotency_record')]
#[ORM\UniqueConstraint(name: 'uniq_idempotency_credential_key', columns: ['api_credential_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_idempotency_expires', columns: ['expires_at'])]
class IdempotencyRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiCredential::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ApiCredential $apiCredential;

    #[ORM\Column(length: 128, updatable: false)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64, updatable: false, options: ['fixed' => true])]
    private string $requestFingerprint;

    #[ORM\ManyToOne(targetEntity: ServiceRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ServiceRequest $serviceRequest;

    #[ORM\Column(type: Types::SMALLINT, updatable: false)]
    private int $responseStatus = 201;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], updatable: false)]
    private array $responseBody;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, updatable: false)]
    private \DateTimeImmutable $expiresAt;

    /** @param array<string, mixed> $responseBody */
    public function __construct(
        ApiCredential $apiCredential,
        string $idempotencyKey,
        string $requestFingerprint,
        ServiceRequest $serviceRequest,
        array $responseBody,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        if ($apiCredential->getClient() !== $serviceRequest->getClient()) {
            throw new \DomainException('Idempotency records must remain inside one client boundary.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || preg_match('/[^!-~]/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Idempotency key must contain 1 to 128 visible ASCII characters.');
        }
        $requestFingerprint = strtolower($requestFingerprint);
        if (!preg_match('/^[a-f0-9]{64}$/', $requestFingerprint)) {
            throw new \InvalidArgumentException('Request fingerprint must be a SHA-256 digest.');
        }
        if ($responseBody === []) {
            throw new \InvalidArgumentException('A replay response body is required.');
        }

        $this->createdAt = $createdAt ?? self::now();
        $this->expiresAt = $expiresAt ?? $this->createdAt->modify('+24 hours');
        if ($this->expiresAt <= $this->createdAt) {
            throw new \InvalidArgumentException('Idempotency expiry must be after creation.');
        }

        $this->apiCredential = $apiCredential;
        $this->idempotencyKey = $idempotencyKey;
        $this->requestFingerprint = $requestFingerprint;
        $this->serviceRequest = $serviceRequest;
        $this->responseBody = $responseBody;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApiCredential(): ApiCredential
    {
        return $this->apiCredential;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getRequestFingerprint(): string
    {
        return $this->requestFingerprint;
    }

    public function getServiceRequest(): ServiceRequest
    {
        return $this->serviceRequest;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    /** @return array<string, mixed> */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $at = null): bool
    {
        return $this->expiresAt <= ($at ?? self::now());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
