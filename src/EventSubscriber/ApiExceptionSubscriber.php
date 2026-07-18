<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ApiPrincipal;
use App\Service\ApiProblemException;
use App\Service\ApiProblemResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiProblemResponseFactory $problems,
        private LoggerInterface $logger,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isApi($request->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof ApiProblemException) {
            $event->setResponse($this->problems->create(
                $request,
                $exception->status,
                $exception->problemCode,
                $exception->title,
                $exception->getMessage(),
                $exception->errors,
                $exception->headers,
            ));

            return;
        }

        if ($exception instanceof AuthenticationException) {
            $event->setResponse($this->problems->create(
                $request,
                Response::HTTP_UNAUTHORIZED,
                'unauthorized',
                'Unauthorized',
                'Valid authentication is required.',
                headers: ['WWW-Authenticate' => 'Bearer'],
            ));

            return;
        }

        if ($exception instanceof NotFoundHttpException) {
            $event->setResponse($this->problems->create(
                $request,
                Response::HTTP_NOT_FOUND,
                'not_found',
                'Not Found',
                'The requested resource was not found.',
            ));

            return;
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            $event->setResponse($this->problems->create(
                $request,
                Response::HTTP_METHOD_NOT_ALLOWED,
                'method_not_allowed',
                'Method Not Allowed',
                'The request method is not allowed for this resource.',
                headers: $exception->getHeaders(),
            ));

            return;
        }

        if ($exception instanceof AccessDeniedException) {
            $authenticated = $this->tokenStorage->getToken()?->getUser() instanceof ApiPrincipal;
            $event->setResponse($this->problems->create(
                $request,
                $authenticated ? Response::HTTP_FORBIDDEN : Response::HTTP_UNAUTHORIZED,
                $authenticated ? 'forbidden' : 'unauthorized',
                $authenticated ? 'Forbidden' : 'Unauthorized',
                $authenticated ? 'Access to this resource is forbidden.' : 'Valid authentication is required.',
                headers: $authenticated ? [] : ['WWW-Authenticate' => 'Bearer'],
            ));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $event->setResponse($this->problems->create(
                $request,
                $exception->getStatusCode(),
                'request_failed',
                Response::$statusTexts[$exception->getStatusCode()] ?? 'Request Failed',
                'The request could not be completed.',
                headers: $exception->getHeaders(),
            ));

            return;
        }

        $this->logger->error('Unhandled API failure.', [
            'exception_class' => $exception::class,
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $exception->getTraceAsString(),
            'trace_id' => $request->attributes->getString('trace_id'),
        ]);
        $event->setResponse($this->problems->create(
            $request,
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'internal_error',
            'Internal Server Error',
            'The request could not be completed.',
        ));
    }

    private function isApi(string $path): bool
    {
        return $path === '/api/v1' || str_starts_with($path, '/api/v1/');
    }
}
