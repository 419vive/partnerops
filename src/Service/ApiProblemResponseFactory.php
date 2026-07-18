<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ApiProblemResponseFactory
{
    /**
     * @param list<array{field:string, code:string, message:string}> $errors
     * @param array<string, string> $headers
     */
    public function create(
        Request $request,
        int $status,
        string $code,
        string $title,
        string $detail,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $body = [
            'type' => '/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => $request->getPathInfo(),
            'traceId' => $request->attributes->getString('trace_id'),
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
