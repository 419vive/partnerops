<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AllowancePeriod;
use App\Entity\ApiCredential;
use App\Entity\AuditEvent;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Enum\UserRole;
use App\Repository\AllowancePeriodRepository;
use App\Repository\ApiCredentialRepository;
use App\Repository\ClientRepository;
use App\Repository\ServiceRequestRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\UserRepository;
use App\Service\AllowanceCalculator;
use App\Service\TraceIdProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/admin/clients')]
final class ClientAdminController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly UserRepository $users,
        private readonly AllowancePeriodRepository $allowances,
        private readonly TimeEntryRepository $timeEntries,
        private readonly ServiceRequestRepository $serviceRequests,
        private readonly ApiCredentialRepository $credentials,
        private readonly AllowanceCalculator $allowanceCalculator,
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $forms,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TraceIdProvider $traceIds,
        #[Autowire('%app.api_token_pepper%')]
        private readonly string $apiTokenPepper,
    ) {
    }

    #[Route('', name: 'app_admin_client_index', methods: ['GET'])]
    public function index(): Response
    {
        $today = self::today();
        $now = self::now();
        $clients = $this->clients->findBy([], ['isArchived' => 'ASC', 'name' => 'ASC']);
        $countsByClient = $this->serviceRequests->dashboardCountsByClient($now, $now->modify('+3 days'));
        $currentAllowances = $this->allowances->findApplicableForAllClients($today);
        $usageByAllowance = $this->timeEntries->sumApprovedMinutesForPeriods($currentAllowances);
        $allowanceByClient = [];
        foreach ($currentAllowances as $allowance) {
            $clientId = $allowance->getClient()->getId();
            if ($clientId !== null) {
                $allowanceByClient[$clientId] = $allowance;
            }
        }

        $rows = [];
        foreach ($clients as $client) {
            $clientId = $client->getId();
            $allowance = $clientId === null ? null : ($allowanceByClient[$clientId] ?? null);
            $counts = $clientId === null ? null : ($countsByClient[$clientId] ?? null);
            $rows[] = [
                'client' => $client,
                'counts' => $counts ?? ['open' => 0, 'overdue' => 0, 'dueSoon' => 0, 'unassigned' => 0],
                'allowance' => $allowance,
                'allowanceSummary' => $allowance ? $this->allowanceCalculator->summarize(
                    $allowance,
                    $usageByAllowance[$allowance->getId()] ?? 0,
                ) : null,
            ];
        }

        return $this->render('admin/client/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'app_admin_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->clientForm('client_create', $this->generateUrl('app_admin_client_new'));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name:string, slug:string} $data */
            $data = $form->getData();
            if ($this->clients->findOneBy(['slug' => strtolower(trim($data['slug']))]) instanceof Client) {
                $form->get('slug')->addError(new FormError('這個客戶代碼已被使用。'));

                return $this->render('admin/client/new.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }
            $client = new Client($data['name'], $data['slug']);

            try {
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client): void {
                    $em->persist($client);
                    $em->persist($this->audit('client.created', 'client', $client, $client->getPublicId()));
                });
            } catch (UniqueConstraintViolationException) {
                $form->get('slug')->addError(new FormError('這個客戶代碼已被使用。'));

                return $this->render('admin/client/new.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', '客戶已建立。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $client->getPublicId()]);
        }

        return $this->render('admin/client/new.html.twig', ['form' => $form]);
    }

    #[Route('/{publicId}', name: 'app_admin_client_show', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['GET'])]
    public function show(string $publicId): Response
    {
        return $this->renderDetail($this->client($publicId));
    }

    #[Route('/{publicId}/edit', name: 'app_admin_client_edit', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function edit(string $publicId, Request $request): Response
    {
        $client = $this->client($publicId);
        $form = $this->clientForm('client_edit', $this->generateUrl('app_admin_client_edit', ['publicId' => $publicId]), [
            'name' => $client->getName(),
            'slug' => $client->getSlug(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($client, editForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{name:string, slug:string} $data */
        $data = $form->getData();
        $existing = $this->clients->findOneBy(['slug' => strtolower(trim($data['slug']))]);
        if ($existing instanceof Client && $existing->getPublicId() !== $client->getPublicId()) {
            $form->get('slug')->addError(new FormError('這個客戶代碼已被使用。'));

            return $this->renderDetail($client, editForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $data): void {
                $client->rename($data['name']);
                $client->changeSlug($data['slug']);
                $em->persist($this->audit('client.updated', 'client', $client, $client->getPublicId(), ['changed_fields' => 'name,slug']));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', '客戶代碼剛被其他資料使用，請重新整理後再試。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        $this->addFlash('success', '客戶資料已更新。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/archive', name: 'app_admin_client_archive', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function archive(string $publicId, Request $request): RedirectResponse
    {
        $client = $this->client($publicId);
        if (!$this->isCsrfTokenValid('archive_client_'.$publicId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client): void {
            $client->archive();
            foreach ($this->users->findBy(['client' => $client, 'isActive' => true]) as $user) {
                $user->deactivate();
            }
            foreach ($this->credentials->findBy(['client' => $client, 'revokedAt' => null]) as $credential) {
                $credential->revoke();
            }
            $em->persist($this->audit('client.archived', 'client', $client, $client->getPublicId()));
        });

        $this->addFlash('success', '客戶已封存；既有歷史仍保留。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/users', name: 'app_admin_client_user_new', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function addUser(string $publicId, Request $request): Response
    {
        $client = $this->activeClient($publicId);
        $form = $this->userForm($client);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($client, userForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{displayName:string, email:string, password:string} $data */
        $data = $form->getData();
        if ($this->users->findOneByEmail($data['email']) instanceof User) {
            $form->get('email')->addError(new FormError('這個電子郵件已被使用。'));

            return $this->renderDetail($client, userForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $user = new User($data['email'], 'pending', $data['displayName'], UserRole::Client, $client);
        $user->changePasswordHash($this->passwordHasher->hashPassword($user, $data['password']), false);

        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $user): void {
                $em->persist($user);
                $em->persist($this->audit('user.created', 'user', $client, $user->getPublicId(), ['role' => UserRole::Client->value]));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', '電子郵件剛被其他帳號使用，請重新整理後再試。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        $this->addFlash('success', '客戶聯絡人已建立。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/users/{userPublicId}/edit', name: 'app_admin_client_user_edit', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}', 'userPublicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function editUser(string $publicId, string $userPublicId, Request $request): Response
    {
        $client = $this->activeClient($publicId);
        $user = $this->users->findOneBy(['publicId' => $userPublicId, 'client' => $client]);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $form = $this->userEditForm($client, $user);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($client, editedUser: $user, userEditForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{displayName:string, email:string, password:?string} $data */
        $data = $form->getData();
        $email = strtolower(trim($data['email']));
        $existing = $this->users->findOneByEmail($email);
        if ($existing instanceof User && $existing->getPublicId() !== $user->getPublicId()) {
            $form->get('email')->addError(new FormError('這個電子郵件已被使用。'));

            return $this->renderDetail($client, editedUser: $user, userEditForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $displayName = trim($data['displayName']);
        $changedFields = [];
        if ($displayName !== $user->getDisplayName()) {
            $changedFields[] = 'display_name';
        }
        if ($email !== $user->getEmail()) {
            $changedFields[] = 'email';
        }
        if ($data['password'] !== null && $data['password'] !== '') {
            $changedFields[] = 'password';
        }

        if ($changedFields === []) {
            $this->addFlash('success', '聯絡人資料沒有變更。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $user, $displayName, $email, $data, $changedFields): void {
                $user->changeDisplayName($displayName);
                $user->changeEmail($email);
                if ($data['password'] !== null && $data['password'] !== '') {
                    $user->changePasswordHash($this->passwordHasher->hashPassword($user, $data['password']));
                }
                $em->persist($this->audit('user.updated', 'user', $client, $user->getPublicId(), [
                    'changed_fields' => implode(',', $changedFields),
                ]));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', '電子郵件剛被其他帳號使用，請重新整理後再試。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        $this->addFlash('success', '聯絡人資料已更新。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/users/{userPublicId}/deactivate', name: 'app_admin_client_user_deactivate', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}', 'userPublicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function deactivateUser(string $publicId, string $userPublicId, Request $request): RedirectResponse
    {
        $client = $this->client($publicId);
        $user = $this->users->findOneBy(['publicId' => $userPublicId, 'client' => $client]);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('deactivate_user_'.$userPublicId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $user): void {
            $user->deactivate();
            $em->persist($this->audit('user.deactivated', 'user', $client, $user->getPublicId(), ['role' => $user->getRole()->value]));
        });
        $this->addFlash('success', '帳號已停用。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/allowances', name: 'app_admin_client_allowance_new', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function addAllowance(string $publicId, Request $request): Response
    {
        $client = $this->activeClient($publicId);
        $form = $this->allowanceForm($client);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($client, allowanceForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{startsOn:\DateTimeImmutable, endsOn:\DateTimeImmutable, includedMinutes:int} $data */
        $data = $form->getData();
        if ($this->allowances->hasOverlap($client, $data['startsOn'], $data['endsOn'])) {
            $form->get('startsOn')->addError(new FormError('這段日期與現有額度期間重疊。'));

            return $this->renderDetail($client, allowanceForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $allowance = new AllowancePeriod($client, $data['startsOn'], $data['endsOn'], $data['includedMinutes'], $this->admin());
        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $allowance): void {
                $em->persist($allowance);
                $em->persist($this->audit('allowance.created', 'allowance_period', $client, $allowance->getPublicId(), [
                    'starts_on' => $allowance->getStartsOn()->format('Y-m-d'),
                    'ends_on' => $allowance->getEndsOn()->format('Y-m-d'),
                    'included_minutes' => $allowance->getIncludedMinutes(),
                ]));
            });
        } catch (DriverException $exception) {
            if (!in_array($exception->getSQLState(), ['23505', '23P01'], true)) {
                throw $exception;
            }
            $this->addFlash('error', '額度期間剛與其他變更發生衝突，請重新整理後再試。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        $this->addFlash('success', '顧問額度期間已建立。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    #[Route('/{publicId}/credentials', name: 'app_admin_client_credential_new', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function addCredential(string $publicId, Request $request): Response
    {
        $client = $this->activeClient($publicId);
        $form = $this->credentialForm($client);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDetail($client, credentialForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{name:string} $data */
        $data = $form->getData();
        if ($this->credentials->activeNameExists($client, $data['name'])) {
            $form->get('name')->addError(new FormError('這個整合名稱已有啟用中的憑證。'));

            return $this->renderDetail($client, credentialForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $selector = self::randomTokenPart(9);
        $secret = self::randomTokenPart(32);
        $token = 'ptk_'.$selector.'.'.$secret;
        $credential = new ApiCredential(
            $client,
            $data['name'],
            $selector,
            substr($token, 0, 24),
            hash_hmac('sha256', $secret, $this->apiTokenPepper),
            $this->admin(),
        );

        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $credential): void {
                $em->persist($credential);
                $em->persist($this->audit('credential.created', 'api_credential', $client, $credential->getPublicId(), ['selector' => $credential->getSelector()]));
            });
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', '整合名稱或憑證識別碼剛被使用，請重新整理後再試。');

            return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
        }

        $response = $this->render('admin/client/credential_created.html.twig', ['client' => $client, 'credential' => $credential, 'token' => $token]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/{publicId}/credentials/{credentialPublicId}/revoke', name: 'app_admin_client_credential_revoke', requirements: ['publicId' => '[0-9A-HJKMNP-TV-Z]{26}', 'credentialPublicId' => '[0-9A-HJKMNP-TV-Z]{26}'], methods: ['POST'])]
    public function revokeCredential(string $publicId, string $credentialPublicId, Request $request): RedirectResponse
    {
        $client = $this->client($publicId);
        $credential = $this->credentials->findOneBy(['publicId' => $credentialPublicId, 'client' => $client]);
        if (!$credential instanceof ApiCredential) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('revoke_credential_'.$credentialPublicId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($client, $credential): void {
            $credential->revoke();
            $em->persist($this->audit('credential.revoked', 'api_credential', $client, $credential->getPublicId(), ['selector' => $credential->getSelector()]));
        });
        $this->addFlash('success', 'API 憑證已撤銷。');

        return $this->redirectToRoute('app_admin_client_show', ['publicId' => $publicId]);
    }

    /**
     * @param FormInterface<array<string, mixed>>|null $editForm
     * @param FormInterface<array<string, mixed>>|null $userForm
     * @param FormInterface<array<string, mixed>>|null $userEditForm
     * @param FormInterface<array<string, mixed>>|null $allowanceForm
     * @param FormInterface<array<string, mixed>>|null $credentialForm
     */
    private function renderDetail(
        Client $client,
        ?FormInterface $editForm = null,
        ?FormInterface $userForm = null,
        ?User $editedUser = null,
        ?FormInterface $userEditForm = null,
        ?FormInterface $allowanceForm = null,
        ?FormInterface $credentialForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $periods = $this->allowances->findBy(['client' => $client], ['startsOn' => 'DESC']);
        $usageByAllowance = $this->timeEntries->sumApprovedMinutesForPeriods($periods);
        $allowanceRows = array_map(fn (AllowancePeriod $period): array => [
            'period' => $period,
            'summary' => $this->allowanceCalculator->summarize(
                $period,
                $usageByAllowance[$period->getId()] ?? 0,
            ),
        ], $periods);

        $users = $this->users->findBy(['client' => $client], ['isActive' => 'DESC', 'displayName' => 'ASC']);
        $userEditForms = [];
        foreach ($users as $user) {
            $userEditForms[$user->getPublicId()] = ($editedUser === $user && $userEditForm !== null
                ? $userEditForm
                : $this->userEditForm($client, $user))->createView();
        }

        return $this->render('admin/client/show.html.twig', [
            'client' => $client,
            'counts' => $this->serviceRequests->dashboardCounts(self::now(), self::now()->modify('+3 days'), $client),
            'users' => $users,
            'userEditForms' => $userEditForms,
            'requests' => $this->serviceRequests->findFilteredPage(1, 10, $client),
            'allowanceRows' => $allowanceRows,
            'credentials' => $this->credentials->findBy(['client' => $client], ['createdAt' => 'DESC']),
            'editForm' => ($editForm ?? $this->clientForm('client_edit', $this->generateUrl('app_admin_client_edit', ['publicId' => $client->getPublicId()]), ['name' => $client->getName(), 'slug' => $client->getSlug()]))->createView(),
            'userForm' => ($userForm ?? $this->userForm($client))->createView(),
            'allowanceForm' => ($allowanceForm ?? $this->allowanceForm($client))->createView(),
            'credentialForm' => ($credentialForm ?? $this->credentialForm($client))->createView(),
        ], new Response(status: $status));
    }

    /**
     * @param array{name:string, slug:string}|null $data
     * @return FormInterface<array<string, mixed>>
     */
    private function clientForm(string $name, string $action, ?array $data = null): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, $data, ['method' => 'POST', 'action' => $action])
            ->add('name', TextType::class, ['label' => '客戶名稱', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 120)]])
            ->add('slug', TextType::class, ['label' => '客戶代碼', 'help' => '僅使用小寫英文、數字與連字號。', 'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'), new Assert\Length(max: 80)]])
            ->getForm();
    }

    /** @return FormInterface<array<string, mixed>> */
    private function userForm(Client $client): FormInterface
    {
        return $this->forms->createNamedBuilder('user_create', FormType::class, null, [
            'method' => 'POST',
            'action' => $this->generateUrl('app_admin_client_user_new', ['publicId' => $client->getPublicId()]),
        ])
            ->add('displayName', TextType::class, ['label' => '姓名', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)]])
            ->add('email', TextType::class, ['label' => '電子郵件', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)]])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => '初始密碼'],
                'second_options' => ['label' => '再次輸入密碼'],
                'invalid_message' => '兩次輸入的密碼不一致。',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 128)],
            ])
            ->getForm();
    }

    /** @return FormInterface<array<string, mixed>> */
    private function userEditForm(Client $client, User $user): FormInterface
    {
        $name = 'user_edit_'.$user->getPublicId();

        return $this->forms->createNamedBuilder($name, FormType::class, [
            'displayName' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'password' => null,
        ], [
            'method' => 'POST',
            'action' => $this->generateUrl('app_admin_client_user_edit', [
                'publicId' => $client->getPublicId(),
                'userPublicId' => $user->getPublicId(),
            ]),
        ])
            ->add('displayName', TextType::class, ['label' => '姓名', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)]])
            ->add('email', TextType::class, ['label' => '電子郵件', 'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)]])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => false,
                'first_options' => ['label' => '新密碼（選填）', 'required' => false],
                'second_options' => ['label' => '再次輸入新密碼', 'required' => false],
                'invalid_message' => '兩次輸入的密碼不一致。',
                'constraints' => [new Assert\Length(min: 12, max: 128)],
            ])
            ->getForm();
    }

    /** @return FormInterface<array<string, mixed>> */
    private function allowanceForm(Client $client): FormInterface
    {
        return $this->forms->createNamedBuilder('allowance_create', FormType::class, null, [
            'method' => 'POST',
            'action' => $this->generateUrl('app_admin_client_allowance_new', ['publicId' => $client->getPublicId()]),
        ])
            ->add('startsOn', DateType::class, ['label' => '開始日期', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotBlank()]])
            ->add('endsOn', DateType::class, ['label' => '結束日期', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotBlank()]])
            ->add('includedMinutes', IntegerType::class, ['label' => '包含分鐘數', 'help' => '20 小時請輸入 1200。', 'constraints' => [new Assert\Range(min: 1, max: 1000000)]])
            ->getForm();
    }

    /** @return FormInterface<array<string, mixed>> */
    private function credentialForm(Client $client): FormInterface
    {
        return $this->forms->createNamedBuilder('credential_create', FormType::class, null, [
            'method' => 'POST',
            'action' => $this->generateUrl('app_admin_client_credential_new', ['publicId' => $client->getPublicId()]),
        ])
            ->add('name', TextType::class, ['label' => '整合名稱', 'help' => '例如 ERP 進件或 LINE 表單。', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 2, max: 100)]])
            ->getForm();
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function audit(string $action, string $subjectType, Client $client, string $subjectPublicId, array $metadata = []): AuditEvent
    {
        return new AuditEvent(
            $action,
            $subjectType,
            $this->traceIds->current(),
            AuditActorType::User,
            $client,
            $this->admin(),
            $subjectPublicId,
            $metadata,
        );
    }

    private function client(string $publicId): Client
    {
        $client = $this->clients->findOneBy(['publicId' => $publicId]);
        if (!$client instanceof Client) {
            throw $this->createNotFoundException();
        }

        return $client;
    }

    private function activeClient(string $publicId): Client
    {
        $client = $this->client($publicId);
        if ($client->isArchived()) {
            throw $this->createNotFoundException();
        }

        return $client;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getRole() !== UserRole::Admin || !$user->isActive()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @param positive-int $bytes */
    private static function randomTokenPart(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function today(): \DateTimeImmutable
    {
        $taipei = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Taipei'));

        return new \DateTimeImmutable($taipei->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
