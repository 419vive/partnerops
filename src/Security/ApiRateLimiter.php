<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ApiRateLimiter
{
    public function __construct(
        private RateLimiterFactory $anonymousLimiter,
        private RateLimiterFactory $credentialLimiter,
    ) {
    }

    public function anonymous(Request $request): RateLimit
    {
        $key = hash('sha256', $request->getClientIp() ?? 'unknown');

        return $this->consume($this->anonymousLimiter, $key, 'anonymous');
    }

    public function credential(ApiPrincipal $principal): RateLimit
    {
        $key = $principal->getUserIdentifier();

        return $this->consume($this->credentialLimiter, $key, 'credential');
    }

    /** @return array<string, string> */
    public function headers(RateLimit $limit): array
    {
        $headers = [
            'RateLimit-Limit' => (string) $limit->getLimit(),
            'RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
        ];
        if (!$limit->isAccepted()) {
            $headers['Retry-After'] = (string) max(
                1,
                (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true)),
            );
        }

        return $headers;
    }

    private function consume(RateLimiterFactory $factory, string $key, string $scope): RateLimit
    {
        $lockName = substr(hash('sha256', $scope.':'.$key), 0, 2);
        $handle = @fopen(sys_get_temp_dir().'/partnerops-rate-'.$lockName.'.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('The API rate limiter lock is unavailable.');
        }

        try {
            return $factory->create($key)->consume();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
