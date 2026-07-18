<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Ulid;

final class TraceIdProvider
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function current(): string
    {
        $traceId = $this->requestStack->getCurrentRequest()?->attributes->getString('trace_id') ?? '';

        return $traceId !== '' ? $traceId : (string) new Ulid();
    }
}
