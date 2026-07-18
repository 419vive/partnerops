<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\ApiExceptionSubscriber;
use App\Service\ApiProblemResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ApiExceptionSubscriberTest extends TestCase
{
    public function testUnhandledApiFailureLogsSafeDiagnosticLocationAndStack(): void
    {
        $exception = new \RuntimeException('Sensitive runtime detail');
        $request = Request::create('/api/v1/requests/example');
        $request->attributes->set('trace_id', 'trace-for-review');
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Unhandled API failure.', self::callback(static fn (array $context): bool =>
                $context['exception_class'] === \RuntimeException::class
                && $context['exception_file'] === $exception->getFile()
                && $context['exception_line'] === $exception->getLine()
                && $context['exception_trace'] === $exception->getTraceAsString()
                && $context['trace_id'] === 'trace-for-review'
                && !in_array($exception->getMessage(), $context, true)
            ));

        $subscriber = new ApiExceptionSubscriber(
            new ApiProblemResponseFactory(),
            $logger,
            $this->createStub(TokenStorageInterface::class),
        );
        $subscriber->onException($event);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()?->getStatusCode());
        self::assertTrue($event->isPropagationStopped());
        self::assertStringNotContainsString('Sensitive runtime detail', (string) $event->getResponse()?->getContent());
    }
}
