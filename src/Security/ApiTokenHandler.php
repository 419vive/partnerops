<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiCredential;
use App\Repository\ApiCredentialRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public const TOKEN_PATTERN = '/\Aptk_([A-Za-z0-9_-]{4,20})\.([A-Za-z0-9_-]{43})\z/D';

    public function __construct(
        private ApiCredentialRepository $credentials,
        private Connection $connection,
        #[Autowire('%app.api_token_pepper%')]
        private string $pepper,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if (!preg_match(self::TOKEN_PATTERN, $accessToken, $matches)) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $credential = $this->credentials->findActiveBySelector($matches[1]);
        $digest = hash_hmac('sha256', $matches[2], $this->pepper);
        if (!$credential instanceof ApiCredential || !hash_equals($credential->getSecretHash(), $digest)) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $this->recordUsage($credential);

        return new UserBadge(
            'api:'.$credential->getPublicId(),
            static fn (): ApiPrincipal => new ApiPrincipal($credential),
        );
    }

    public static function isWellFormed(string $accessToken): bool
    {
        return preg_match(self::TOKEN_PATTERN, $accessToken) === 1;
    }

    private function recordUsage(ApiCredential $credential): void
    {
        if ($credential->getId() === null) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $this->connection->executeStatement(
                'UPDATE api_credential SET last_used_at = :now WHERE id = :id AND (last_used_at IS NULL OR last_used_at <= :threshold)',
                [
                    'now' => $now,
                    'id' => $credential->getId(),
                    'threshold' => $now->modify('-1 hour'),
                ],
                [
                    'now' => Types::DATETIME_IMMUTABLE,
                    'id' => Types::BIGINT,
                    'threshold' => Types::DATETIME_IMMUTABLE,
                ],
            );
        } catch (DbalException) {
            // Credential usage tracking is best-effort and must not break valid intake.
        }
    }
}
