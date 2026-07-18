<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\ApiProblemException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_api_rate_limit_headers';
    public const ANONYMOUS_CONSUMED = '_api_anonymous_rate_consumed';

    public function __construct(
        private ApiRateLimiter $limits,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['limitAnonymous', 24],
                ['limitCredential', 0],
            ],
            KernelEvents::RESPONSE => ['addHeaders', 0],
        ];
    }

    public function limitAnonymous(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApi($event->getRequest()->getPathInfo())) {
            return;
        }

        $authorization = $event->getRequest()->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')
            && ApiTokenHandler::isWellFormed(substr($authorization, 7))) {
            return;
        }

        $event->getRequest()->attributes->set(self::ANONYMOUS_CONSUMED, true);
        $this->enforce($event, $this->limits->anonymous($event->getRequest()));
    }

    public function limitCredential(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApi($event->getRequest()->getPathInfo())) {
            return;
        }

        $principal = $this->tokenStorage->getToken()?->getUser();
        if (!$principal instanceof ApiPrincipal) {
            return;
        }

        $this->enforce($event, $this->limits->credential($principal));
    }

    public function addHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getRequest()->attributes->get(self::ATTRIBUTE, []);
        if (!is_array($headers)) {
            return;
        }

        foreach ($headers as $name => $value) {
            $event->getResponse()->headers->set((string) $name, (string) $value);
        }
    }

    private function enforce(RequestEvent $event, RateLimit $limit): void
    {
        $headers = $this->limits->headers($limit);
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $headers);

        if ($limit->isAccepted()) {
            return;
        }

        throw new ApiProblemException(
            Response::HTTP_TOO_MANY_REQUESTS,
            'rate_limited',
            'Too Many Requests',
            'The request limit has been exceeded. Retry later.',
            headers: $headers,
        );
    }

    private function isApi(string $path): bool
    {
        return $path === '/api/v1' || str_starts_with($path, '/api/v1/');
    }
}
