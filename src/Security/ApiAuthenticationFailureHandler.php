<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ApiProblemResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class ApiAuthenticationFailureHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private ApiProblemResponseFactory $problems,
        private ApiRateLimiter $limits,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized($request);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->attributes->getBoolean(ApiRateLimitSubscriber::ANONYMOUS_CONSUMED)) {
            return $this->unauthorized($request);
        }

        $limit = $this->limits->anonymous($request);
        $headers = $this->limits->headers($limit);
        if (!$limit->isAccepted()) {
            return $this->problems->create(
                $request,
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limited',
                'Too Many Requests',
                'The request limit has been exceeded. Retry later.',
                headers: $headers,
            );
        }

        return $this->unauthorized($request, $headers);
    }

    /** @param array<string, string> $headers */
    private function unauthorized(Request $request, array $headers = []): Response
    {
        $headers['WWW-Authenticate'] = 'Bearer';

        return $this->problems->create(
            $request,
            Response::HTTP_UNAUTHORIZED,
            'unauthorized',
            'Unauthorized',
            'Valid authentication is required.',
            headers: $headers,
        );
    }
}
