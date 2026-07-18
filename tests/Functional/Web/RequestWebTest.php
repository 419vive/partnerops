<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\AllowancePeriod;
use App\Entity\AuditEvent;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RequestWebTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private Client $acme;
    private Client $globex;
    private User $agent;
    private User $acmeUser;
    private ServiceRequest $acmeRequest;
    private ServiceRequest $globexRequest;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->browser = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->entityManager);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $this->acme = new Client('Acme 電商', 'acme');
        $this->globex = new Client('Globex 創意', 'globex');
        $admin = new User('admin@example.test', 'not-used', '王管理員', UserRole::Admin);
        $this->agent = new User('agent@example.test', 'not-used', '林顧問', UserRole::Agent);
        $this->acmeUser = new User('client@acme.test', 'not-used', '陳小姐', UserRole::Client, $this->acme);
        $globexUser = new User('client@globex.test', 'not-used', '李先生', UserRole::Client, $this->globex);
        $this->acmeRequest = new ServiceRequest(
            $this->acme,
            $this->acmeUser,
            '金流失敗調查',
            '行動版結帳時持續出現金流失敗，請協助調查。',
            RequestPriority::High,
        );
        $this->globexRequest = new ServiceRequest(
            $this->globex,
            $globexUser,
            '尚未公開的品牌改版',
            '這是 Globex 客戶專屬且不得被其他客戶發現的需求。',
        );
        $allowance = new AllowancePeriod(
            $this->acme,
            self::date('2026-07-01'),
            self::date('2026-07-31'),
            1200,
            $admin,
        );

        foreach ([$this->acme, $this->globex, $admin, $this->agent, $this->acmeUser, $globexUser, $allowance, $this->acmeRequest, $this->globexRequest] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        unset($this->entityManager);
    }

    public function testClientDashboardAndRequestListStayClientScoped(): void
    {
        $this->browser->loginUser($this->acmeUser);

        $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('金流失敗調查', (string) $this->browser->getResponse()->getContent());
        self::assertStringNotContainsString('尚未公開的品牌改版', (string) $this->browser->getResponse()->getContent());

        $this->browser->request('GET', '/requests?q=Globex');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('尚未公開的品牌改版', (string) $this->browser->getResponse()->getContent());
    }

    public function testClientReceivesGenericNotFoundForAnotherClientsIdentifier(): void
    {
        $this->browser->loginUser($this->acmeUser);
        $this->browser->request('GET', '/requests/'.$this->globexRequest->getPublicId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('尚未公開的品牌改版', $content);
        self::assertStringNotContainsString('Globex 創意', $content);
    }

    public function testClientCommentIsPublicAndAuditMetadataNeverContainsItsBody(): void
    {
        $body = '客戶補充：今天上午又發生兩次。';
        $this->browser->loginUser($this->acmeUser);
        $crawler = $this->browser->request('GET', '/requests/'.$this->acmeRequest->getPublicId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('新增留言')->form([
            'comment[body]' => $body,
        ]);
        $this->browser->submit($form);

        self::assertResponseRedirects('/requests/'.$this->acmeRequest->getPublicId());

        $comment = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Comment::class)
            ->findOneBy(['body' => $body]);
        self::assertInstanceOf(Comment::class, $comment);
        self::assertFalse($comment->isInternal());

        $audit = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(AuditEvent::class)
            ->findOneBy(['action' => 'comment.added']);
        self::assertInstanceOf(AuditEvent::class, $audit);
        self::assertSame(['is_internal' => false], $audit->getMetadata());
        self::assertStringNotContainsString($body, json_encode($audit->getMetadata(), JSON_THROW_ON_ERROR));
    }

    public function testStaleManageSubmissionDoesNotOverwriteTheRequest(): void
    {
        $this->browser->loginUser($this->agent);
        $crawler = $this->browser->request('GET', '/requests/'.$this->acmeRequest->getPublicId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('儲存工作安排')->form();
        $form['request_manage[priority]']->select(RequestPriority::Urgent->value);
        $form['request_manage[expectedVersion]']->setValue('999');
        $this->browser->submit($form);

        self::assertResponseRedirects('/requests/'.$this->acmeRequest->getPublicId());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $freshRequest = $entityManager->getRepository(ServiceRequest::class)->findOneBy([
            'publicId' => $this->acmeRequest->getPublicId(),
        ]);
        self::assertInstanceOf(ServiceRequest::class, $freshRequest);
        self::assertSame(RequestPriority::High, $freshRequest->getPriority());
        self::assertNull($entityManager->getRepository(AuditEvent::class)->findOneBy(['action' => 'request.updated']));
    }

    public function testTeamMemberCanEditRequestContentWithSafeAuditMetadata(): void
    {
        $this->browser->loginUser($this->agent);
        $crawler = $this->browser->request('GET', '/requests/'.$this->acmeRequest->getPublicId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('儲存工作安排')->form();
        $form['request_manage[title]']->setValue('金流失敗與重複扣款調查');
        $form['request_manage[description]']->setValue('行動版結帳會失敗，部分客戶重新送出後又發生重複扣款，請協助完整追查。');
        $form['request_manage[assignee]']->select((string) $this->agent->getId());
        $this->browser->submit($form);

        self::assertResponseRedirects('/requests/'.$this->acmeRequest->getPublicId());
        $this->browser->followRedirect();
        self::assertStringContainsString('更新請求內容', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('更新負責人', (string) $this->browser->getResponse()->getContent());
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $updated = $manager->getRepository(ServiceRequest::class)->findOneBy([
            'publicId' => $this->acmeRequest->getPublicId(),
        ]);
        self::assertInstanceOf(ServiceRequest::class, $updated);
        self::assertSame('金流失敗與重複扣款調查', $updated->getTitle());

        $audit = $manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'request.updated']);
        self::assertInstanceOf(AuditEvent::class, $audit);
        self::assertTrue($audit->getMetadata()['content_changed']);
        self::assertStringNotContainsString($updated->getDescription(), json_encode($audit->getMetadata(), JSON_THROW_ON_ERROR));
    }

    public function testStatusTransitionAppearsInTheRequestTimeline(): void
    {
        $this->browser->loginUser($this->agent);
        $crawler = $this->browser->request('GET', '/requests/'.$this->acmeRequest->getPublicId());
        $this->browser->submit($crawler->selectButton('變更為處理中')->form());

        self::assertResponseRedirects('/requests/'.$this->acmeRequest->getPublicId());
        $this->browser->followRedirect();
        self::assertStringContainsString('狀態由「新建」變更為「處理中」', (string) $this->browser->getResponse()->getContent());
    }

    public function testExistingSessionIsRevokedAfterUserDeactivation(): void
    {
        $publicId = $this->acmeUser->getPublicId();
        $this->browser->loginUser($this->acmeUser);
        $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $manager->getRepository(User::class)->findOneBy(['publicId' => $publicId]);
        self::assertInstanceOf(User::class, $user);
        $user->deactivate();
        $manager->flush();

        $this->browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testExistingClientSessionIsRevokedAfterClientArchive(): void
    {
        $publicId = $this->acme->getPublicId();
        $this->browser->loginUser($this->acmeUser);
        $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $client = $manager->getRepository(Client::class)->findOneBy(['publicId' => $publicId]);
        self::assertInstanceOf(Client::class, $client);
        $client->archive();
        $manager->flush();

        $this->browser->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
