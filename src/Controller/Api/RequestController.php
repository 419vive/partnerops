<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\ServiceRequestRepository;
use App\Security\ApiPrincipal;
use App\Service\ApiProblemException;
use App\Service\ApiRequestCreator;
use App\Service\ApiRequestInputValidator;
use App\Service\ApiRequestPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RequestController extends AbstractController
{
    #[Route('/api/v1/requests', name: 'api_request_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] ApiPrincipal $principal,
        ApiRequestInputValidator $validator,
        ApiRequestCreator $creator,
    ): JsonResponse {
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (!is_string($idempotencyKey) || !preg_match('/\A[!-~]{8,128}\z/D', $idempotencyKey)) {
            throw new ApiProblemException(
                Response::HTTP_BAD_REQUEST,
                'invalid_idempotency_key',
                'Bad Request',
                'Idempotency-Key must contain 8 to 128 visible ASCII characters.',
            );
        }

        $result = $creator->create(
            $principal->getCredential(),
            $idempotencyKey,
            $validator->validate($request),
        );
        $location = $this->generateUrl('api_request_show', ['publicId' => $result['body']['id']]);

        return new JsonResponse($result['body'], $result['status'], [
            'Location' => $location,
            'Idempotent-Replayed' => $result['replayed'] ? 'true' : 'false',
        ]);
    }

    #[Route(
        '/api/v1/requests/{publicId}',
        name: 'api_request_show',
        requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['GET'],
    )]
    public function show(
        string $publicId,
        Request $request,
        #[CurrentUser] ApiPrincipal $principal,
        ServiceRequestRepository $requests,
        ApiRequestPresenter $presenter,
    ): JsonResponse {
        $serviceRequest = $requests->findOneForClientByPublicId($principal->getClient(), $publicId);
        if ($serviceRequest === null) {
            throw new ApiProblemException(
                Response::HTTP_NOT_FOUND,
                'not_found',
                'Not Found',
                'The requested resource was not found.',
            );
        }

        return new JsonResponse($presenter->present(
            $serviceRequest,
            commentsPage: max(1, $request->query->getInt('commentsPage', 1)),
        ));
    }
}
