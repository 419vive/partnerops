<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    private const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/D';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $candidate = $event->getRequest()->headers->get('X-Request-ID', '');
        $traceId = preg_match(self::PATTERN, $candidate) === 1 ? $candidate : (string) new Ulid();
        $event->getRequest()->attributes->set('trace_id', $traceId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $traceId = $event->getRequest()->attributes->getString('trace_id');
        if ($traceId !== '') {
            $event->getResponse()->headers->set('X-Request-ID', $traceId);
        }
    }
}
