<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\DatabaseReadinessProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->response(['status' => 'live']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(DatabaseReadinessProbe $probe, Request $request): JsonResponse
    {
        try {
            $probe->assertReady();

            return $this->response(['status' => 'ready']);
        } catch (\Throwable) {
            return $this->response([
                'type' => '/problems/not-ready',
                'title' => 'Service unavailable',
                'status' => Response::HTTP_SERVICE_UNAVAILABLE,
                'code' => 'not_ready',
                'detail' => 'The application is not ready to serve traffic.',
                'instance' => '/health/ready',
                'traceId' => $request->attributes->getString('trace_id'),
            ], Response::HTTP_SERVICE_UNAVAILABLE, 'application/problem+json');
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = Response::HTTP_OK, string $contentType = 'application/json'): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
