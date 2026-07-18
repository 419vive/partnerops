<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Client;
use App\Entity\AuditEvent;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\RequestStatus;
use App\Enum\UserRole;
use App\Form\CommentType;
use App\Form\RequestManageType;
use App\Form\RequestType;
use App\Form\TimeEntryType;
use App\Form\TransitionType;
use App\Repository\AllowancePeriodRepository;
use App\Repository\AuditEventRepository;
use App\Repository\ClientRepository;
use App\Repository\CommentRepository;
use App\Repository\ServiceRequestRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ServiceRequestVoter;
use App\Service\AllowanceCalculator;
use App\Service\RequestOperations;
use App\Service\RequestWorkflow;
use App\Service\TraceIdProvider;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RequestController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly ServiceRequestRepository $serviceRequests,
        private readonly CommentRepository $comments,
        private readonly TimeEntryRepository $timeEntries,
        private readonly AllowancePeriodRepository $allowancePeriods,
        private readonly AuditEventRepository $auditEvents,
        private readonly ClientRepository $clients,
        private readonly UserRepository $users,
        private readonly AllowanceCalculator $allowanceCalculator,
        private readonly RequestOperations $operations,
        private readonly RequestWorkflow $workflow,
        private readonly TraceIdProvider $traceIds,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('/requests', name: 'app_request_index', methods: ['GET'])]
    public function index(Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $page = max(1, $httpRequest->query->getInt('page', 1));
        $status = RequestStatus::tryFrom($httpRequest->query->getString('status'));
        $priority = RequestPriority::tryFrom($httpRequest->query->getString('priority'));
        $search = trim(mb_substr($httpRequest->query->getString('q'), 0, 200));
        $clientFilter = trim($httpRequest->query->getString('client'));
        if ($user->getRole() === UserRole::Client) {
            $client = $user->getClient();
            $clientFilter = '';
        } elseif ($clientFilter !== '') {
            $client = $this->clients->findOneBy(['publicId' => $clientFilter]);
            if (!$client instanceof Client) {
                throw $this->createNotFoundException('找不到這個客戶。');
            }
        } else {
            $client = null;
        }

        $total = $this->serviceRequests->countFiltered($client, $status, $priority, $search);

        return $this->render('request/index.html.twig', [
            'requests' => $this->serviceRequests->findFilteredPage($page, self::PAGE_SIZE, $client, $status, $priority, $search),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
            'filters' => [
                'status' => $status === null ? '' : $status->value,
                'priority' => $priority === null ? '' : $priority->value,
                'q' => $search,
                'client' => $clientFilter,
            ],
            'statuses' => RequestStatus::cases(),
            'priorities' => RequestPriority::cases(),
        ]);
    }

    #[Route('/requests/new', name: 'app_request_new', methods: ['GET', 'POST'])]
    public function new(Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $isTeam = $user->canManageWork();
        $form = $this->createForm(RequestType::class, [
            'priority' => RequestPriority::Normal,
        ], [
            'team_mode' => $isTeam,
            'action' => $this->generateUrl('app_request_new'),
            'method' => 'POST',
        ]);
        $form->handleRequest($httpRequest);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{client?:Client, title:string, description:string, priority:RequestPriority} $data */
            $data = $form->getData();
            $client = $isTeam ? ($data['client'] ?? null) : $user->getClient();
            if (!$client instanceof Client) {
                throw new \LogicException('An authenticated request creator must have a client scope.');
            }

            try {
                $serviceRequest = $this->operations->create(
                    $client,
                    $user,
                    $data['title'],
                    $data['description'],
                    $data['priority'],
                    $this->traceIds->current(),
                );
            } catch (\DomainException|\InvalidArgumentException) {
                $form->addError(new FormError('目前無法建立這筆服務請求，請確認客戶與輸入內容。'));

                return $this->formResponse('request/new.html.twig', ['form' => $form->createView()]);
            }

            $this->addFlash('success', '服務請求已建立。');

            return $this->redirectToRoute('app_request_show', ['publicId' => $serviceRequest->getPublicId()]);
        }

        $response = $this->render('request/new.html.twig', ['form' => $form->createView()]);
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/requests/{publicId}', name: 'app_request_show', methods: ['GET'])]
    public function show(string $publicId, Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $commentPage = max(1, $httpRequest->query->getInt('commentsPage', 1));
        $timePage = max(1, $httpRequest->query->getInt('timePage', 1));

        return $this->showResponse(
            $this->findAccessible($publicId, $user),
            $user,
            commentPage: $commentPage,
            timePage: $timePage,
        );
    }

    #[Route('/requests/{publicId}/manage', name: 'app_request_manage', methods: ['POST'])]
    public function manage(string $publicId, Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $serviceRequest = $this->findAccessible($publicId, $user);
        $this->denyAccessUnlessGranted(ServiceRequestVoter::MANAGE, $serviceRequest);

        $form = $this->manageForm($serviceRequest);
        $form->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->showResponse($serviceRequest, $user, manageForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{title:string, description:string, priority:RequestPriority, assignee:?User, dueAt:?\DateTimeImmutable, expectedVersion:string|int} $data */
        $data = $form->getData();
        try {
            $this->operations->update(
                $serviceRequest,
                $user,
                $data['title'],
                $data['description'],
                $data['priority'],
                $data['assignee'],
                $data['dueAt'],
                (int) $data['expectedVersion'],
                $this->traceIds->current(),
            );
        } catch (OptimisticLockException) {
            $this->addFlash('error', '這筆請求已被其他人更新，請重新確認最新內容。');

            return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
        } catch (\DomainException|\InvalidArgumentException) {
            $form->addError(new FormError('無法儲存這次變更，請重新確認工作安排。'));

            return $this->showResponse($serviceRequest, $user, manageForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', '工作安排已更新。');

        return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
    }

    #[Route('/requests/{publicId}/transition/{target}', name: 'app_request_transition', methods: ['POST'])]
    public function transition(string $publicId, string $target, Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $serviceRequest = $this->findAccessible($publicId, $user);
        $this->denyAccessUnlessGranted(ServiceRequestVoter::TRANSITION, $serviceRequest);

        $targetStatus = RequestStatus::tryFrom($target);
        if ($targetStatus === null || !$serviceRequest->getStatus()->canTransitionTo($targetStatus)) {
            throw $this->createNotFoundException('找不到可用的狀態變更。');
        }

        $form = $this->transitionForm($serviceRequest, $targetStatus);
        $form->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->showResponse(
                $serviceRequest,
                $user,
                transitionForms: [$targetStatus->value => $form],
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->workflow->transition(
                $serviceRequest,
                $targetStatus,
                $user,
                $this->traceIds->current(),
                (int) $form->get('expectedVersion')->getData(),
            );
        } catch (OptimisticLockException) {
            $this->addFlash('error', '這筆請求已被其他人更新，請重新確認最新狀態。');

            return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
        } catch (\DomainException|\InvalidArgumentException) {
            $this->addFlash('error', '無法套用這次狀態變更，請重新載入後再試。');

            return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
        }

        $this->addFlash('success', '請求狀態已更新。');

        return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
    }

    #[Route('/requests/{publicId}/comments', name: 'app_request_comment', methods: ['POST'])]
    public function comment(string $publicId, Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $serviceRequest = $this->findAccessible($publicId, $user);
        $this->denyAccessUnlessGranted(ServiceRequestVoter::COMMENT, $serviceRequest);

        $form = $this->commentForm($serviceRequest, $user);
        $form->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->showResponse($serviceRequest, $user, commentForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{body:string, isInternal?:bool} $data */
        $data = $form->getData();
        try {
            $this->operations->addComment(
                $serviceRequest,
                $user,
                $data['body'],
                $user->canManageWork() && ($data['isInternal'] ?? false),
                $this->traceIds->current(),
            );
        } catch (\DomainException|\InvalidArgumentException) {
            $form->addError(new FormError('目前無法新增留言，請重新確認輸入內容。'));

            return $this->showResponse($serviceRequest, $user, commentForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', '留言已新增。');

        return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
    }

    #[Route('/requests/{publicId}/time', name: 'app_request_time', methods: ['POST'])]
    public function time(string $publicId, Request $httpRequest): Response
    {
        $user = $this->currentUser();
        $serviceRequest = $this->findAccessible($publicId, $user);
        $this->denyAccessUnlessGranted(ServiceRequestVoter::LOG_TIME, $serviceRequest);

        $form = $this->timeForm($serviceRequest);
        $form->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->showResponse($serviceRequest, $user, timeForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{minutes:int, description:string, workDate:\DateTimeImmutable, isClientVisible:bool} $data */
        $data = $form->getData();
        try {
            $this->operations->addTime(
                $serviceRequest,
                $user,
                (int) $data['minutes'],
                $data['description'],
                $data['workDate'],
                (bool) $data['isClientVisible'],
                $this->traceIds->current(),
            );
        } catch (\DomainException|\InvalidArgumentException) {
            $form->addError(new FormError('選擇的工作日期沒有可用額度，或工時內容無效。'));

            return $this->showResponse($serviceRequest, $user, timeForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', '工時已記錄。');

        return $this->redirectToRoute('app_request_show', ['publicId' => $publicId]);
    }

    /**
     * @param FormInterface<array<string, mixed>>|null $manageForm
     * @param FormInterface<array<string, mixed>>|null $commentForm
     * @param FormInterface<array<string, mixed>>|null $timeForm
     * @param array<string, FormInterface<mixed>> $transitionForms
     */
    private function showResponse(
        ServiceRequest $serviceRequest,
        User $user,
        ?FormInterface $manageForm = null,
        ?FormInterface $commentForm = null,
        ?FormInterface $timeForm = null,
        array $transitionForms = [],
        int $commentPage = 1,
        int $timePage = 1,
        int $status = Response::HTTP_OK,
    ): Response {
        $isTeam = $user->canManageWork();
        $today = self::taipeiCalendarDate();
        $allowance = $this->allowancePeriods->findApplicable($serviceRequest->getClient(), $today);
        $allowanceSummary = $allowance === null ? null : $this->allowanceCalculator->summarize(
            $allowance,
            $this->timeEntries->sumApprovedMinutes($allowance),
        );

        $commentTotal = $isTeam
            ? $this->comments->countForRequest($serviceRequest)
            : $this->comments->countClientVisible($serviceRequest->getClient(), $serviceRequest);
        $commentPages = max(1, (int) ceil($commentTotal / CommentRepository::PAGE_SIZE));
        $commentPage = min(max(1, $commentPage), $commentPages);
        $comments = $isTeam
            ? $this->comments->findChronologicalForRequest($serviceRequest, $commentPage)
            : $this->comments->findClientVisible($serviceRequest->getClient(), $serviceRequest, $commentPage);
        $timeTotal = $this->timeEntries->countForRequest($serviceRequest, !$isTeam);
        $timePages = max(1, (int) ceil($timeTotal / TimeEntryRepository::PAGE_SIZE));
        $timePage = min(max(1, $timePage), $timePages);
        $timeEntries = $this->timeEntries->findRecentForRequest($serviceRequest, !$isTeam, $timePage);
        $timeline = $this->buildTimeline($serviceRequest, $comments, $commentPage === 1);

        $transitionViews = [];
        if ($isTeam && !$serviceRequest->getClient()->isArchived()) {
            foreach (RequestStatus::cases() as $target) {
                if (!$serviceRequest->getStatus()->canTransitionTo($target)) {
                    continue;
                }
                $transitionForm = $transitionForms[$target->value] ?? $this->transitionForm($serviceRequest, $target);
                $transitionViews[] = [
                    'target' => $target,
                    'label' => $target->label(),
                    'form' => $transitionForm->createView(),
                ];
            }
        }

        $response = $this->render('request/show.html.twig', [
            'serviceRequest' => $serviceRequest,
            'timeline' => $timeline,
            'commentPage' => $commentPage,
            'commentPages' => $commentPages,
            'commentTotal' => $commentTotal,
            'timeEntries' => $timeEntries,
            'timePage' => $timePage,
            'timePages' => $timePages,
            'timeTotal' => $timeTotal,
            'allowance' => $allowance,
            'allowanceSummary' => $allowanceSummary,
            'assignees' => $isTeam ? $this->users->findActiveAssignees() : [],
            'transitions' => $transitionViews,
            'manageForm' => $isTeam ? ($manageForm ?? $this->manageForm($serviceRequest))->createView() : null,
            'commentForm' => ($commentForm ?? $this->commentForm($serviceRequest, $user))->createView(),
            'timeForm' => $isTeam ? ($timeForm ?? $this->timeForm($serviceRequest))->createView() : null,
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /** @return FormInterface<array<string, mixed>> */
    private function manageForm(ServiceRequest $serviceRequest): FormInterface
    {
        return $this->createForm(RequestManageType::class, [
            'title' => $serviceRequest->getTitle(),
            'description' => $serviceRequest->getDescription(),
            'priority' => $serviceRequest->getPriority(),
            'assignee' => $serviceRequest->getAssignee(),
            'dueAt' => $serviceRequest->getDueAt(),
            'expectedVersion' => $serviceRequest->getVersion(),
        ], [
            'action' => $this->generateUrl('app_request_manage', ['publicId' => $serviceRequest->getPublicId()]),
            'method' => 'POST',
        ]);
    }

    /** @return FormInterface<array<string, mixed>> */
    private function commentForm(ServiceRequest $serviceRequest, User $user): FormInterface
    {
        return $this->createForm(CommentType::class, ['isInternal' => false], [
            'allow_internal' => $user->canManageWork(),
            'action' => $this->generateUrl('app_request_comment', ['publicId' => $serviceRequest->getPublicId()]),
            'method' => 'POST',
        ]);
    }

    /** @return FormInterface<array<string, mixed>> */
    private function timeForm(ServiceRequest $serviceRequest): FormInterface
    {
        return $this->createForm(TimeEntryType::class, [
            'workDate' => self::taipeiCalendarDate(),
            'isClientVisible' => true,
        ], [
            'action' => $this->generateUrl('app_request_time', ['publicId' => $serviceRequest->getPublicId()]),
            'method' => 'POST',
        ]);
    }

    /** @return FormInterface<array<string, mixed>> */
    private function transitionForm(ServiceRequest $serviceRequest, RequestStatus $target): FormInterface
    {
        return $this->formFactory->createNamed('transition_'.$target->value, TransitionType::class, [
            'expectedVersion' => $serviceRequest->getVersion(),
        ], [
            'submit_label' => '變更為'.$target->label(),
            'action' => $this->generateUrl('app_request_transition', [
                'publicId' => $serviceRequest->getPublicId(),
                'target' => $target->value,
            ]),
            'method' => 'POST',
        ]);
    }

    private function findAccessible(string $publicId, User $user): ServiceRequest
    {
        $serviceRequest = $user->getRole() === UserRole::Client && $user->getClient() !== null
            ? $this->serviceRequests->findOneForClientByPublicId($user->getClient(), $publicId)
            : $this->serviceRequests->findOneBy(['publicId' => $publicId]);
        if (!$serviceRequest instanceof ServiceRequest) {
            throw $this->createNotFoundException('找不到這筆服務請求。');
        }

        $this->denyAccessUnlessGranted(ServiceRequestVoter::VIEW, $serviceRequest);

        return $serviceRequest;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param list<\App\Entity\Comment> $comments
     * @return list<array{actor:string, body:string, occurredAt:\DateTimeImmutable, internal:bool, kind:string}>
     */
    private function buildTimeline(ServiceRequest $serviceRequest, array $comments, bool $includeActivity): array
    {
        $timeline = [];
        if ($includeActivity) {
            foreach ($this->auditEvents->findRequestTimeline($serviceRequest) as $event) {
                $timeline[] = [
                    'actor' => $event->getActor()?->getDisplayName() ?? ($event->getActorType()->value === 'api_credential' ? 'API 整合' : '系統'),
                    'body' => self::activityDescription($event),
                    'occurredAt' => $event->getOccurredAt(),
                    'internal' => false,
                    'kind' => 'event',
                ];
            }
        }
        foreach ($comments as $comment) {
            $timeline[] = [
                'actor' => $comment->getAuthor()->getDisplayName(),
                'body' => $comment->getBody(),
                'occurredAt' => $comment->getCreatedAt(),
                'internal' => $comment->isInternal(),
                'kind' => 'comment',
            ];
        }

        usort($timeline, static fn (array $left, array $right): int => $left['occurredAt'] <=> $right['occurredAt']);

        return array_slice($timeline, -300);
    }

    private static function activityDescription(AuditEvent $event): string
    {
        $metadata = $event->getMetadata();
        if ($event->getAction() === 'request.created') {
            return '建立服務請求';
        }
        if ($event->getAction() === 'request.status_changed') {
            $from = RequestStatus::tryFrom((string) ($metadata['from_status'] ?? ''))?->label() ?? '未知';
            $to = RequestStatus::tryFrom((string) ($metadata['to_status'] ?? ''))?->label() ?? '未知';

            return sprintf('狀態由「%s」變更為「%s」', $from, $to);
        }

        $changes = [];
        if (($metadata['content_changed'] ?? false) === true) {
            $changes[] = '更新請求內容';
        }
        if (($metadata['from_priority'] ?? null) !== ($metadata['to_priority'] ?? null)) {
            $from = RequestPriority::tryFrom((string) ($metadata['from_priority'] ?? ''))?->label() ?? '未知';
            $to = RequestPriority::tryFrom((string) ($metadata['to_priority'] ?? ''))?->label() ?? '未知';
            $changes[] = sprintf('優先級由「%s」調整為「%s」', $from, $to);
        }
        if (($metadata['from_assignee'] ?? null) !== ($metadata['to_assignee'] ?? null)) {
            $changes[] = '更新負責人';
        }
        if (($metadata['from_due_at'] ?? null) !== ($metadata['to_due_at'] ?? null)) {
            $changes[] = '更新預計完成時間';
        }

        return $changes === [] ? '更新工作安排' : implode('；', $changes);
    }

    /** @param array<string, mixed> $context */
    private function formResponse(string $template, array $context): Response
    {
        $response = $this->render($template, $context);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    private static function taipeiCalendarDate(): \DateTimeImmutable
    {
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));

        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
