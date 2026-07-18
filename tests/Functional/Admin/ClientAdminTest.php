<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditEvent;
use App\Entity\ApiCredential;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ClientAdminTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $entityManager;
    private Client $acme;
    private User $admin;
    private User $acmeUser;
    private User $globexUser;

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
        $globex = new Client('Globex 創意', 'globex');
        $this->admin = new User('admin@example.test', 'not-used', '王管理員', UserRole::Admin);
        $this->acmeUser = new User('client@acme.test', 'not-used', '陳小姐', UserRole::Client, $this->acme);
        $this->globexUser = new User('client@globex.test', 'not-used', '李先生', UserRole::Client, $globex);

        foreach ([$this->acme, $globex, $this->admin, $this->acmeUser, $this->globexUser] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->browser->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        unset($this->entityManager);
    }

    public function testAdministratorCanEditClientUserAndResetPasswordWithoutLeakingItToAudit(): void
    {
        $path = sprintf(
            '/admin/clients/%s/users/%s/edit',
            $this->acme->getPublicId(),
            $this->acmeUser->getPublicId(),
        );
        $formName = 'user_edit_'.$this->acmeUser->getPublicId();
        $crawler = $this->browser->request('GET', '/admin/clients/'.$this->acme->getPublicId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="%s"]', $path))->form([
            $formName.'[displayName]' => '陳怡君',
            $formName.'[email]' => 'jane@acme.test',
            $formName.'[password][first]' => 'Commercial!2026',
            $formName.'[password][second]' => 'Commercial!2026',
        ]);
        $this->browser->submit($form);

        self::assertResponseRedirects('/admin/clients/'.$this->acme->getPublicId());
        $this->entityManager->clear();

        $updated = $this->entityManager->getRepository(User::class)->findOneBy([
            'publicId' => $this->acmeUser->getPublicId(),
        ]);
        self::assertInstanceOf(User::class, $updated);
        self::assertSame('陳怡君', $updated->getDisplayName());
        self::assertSame('jane@acme.test', $updated->getEmail());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($updated, 'Commercial!2026'));

        $audit = $this->entityManager->getRepository(AuditEvent::class)->findOneBy(['action' => 'user.updated']);
        self::assertInstanceOf(AuditEvent::class, $audit);
        self::assertSame(['changed_fields' => 'display_name,email,password'], $audit->getMetadata());
        self::assertStringNotContainsString('Commercial!2026', json_encode($audit->getMetadata(), JSON_THROW_ON_ERROR));
    }

    public function testDuplicateUserEmailRendersValidationErrorWithoutChangingEitherAccount(): void
    {
        $path = sprintf(
            '/admin/clients/%s/users/%s/edit',
            $this->acme->getPublicId(),
            $this->acmeUser->getPublicId(),
        );
        $formName = 'user_edit_'.$this->acmeUser->getPublicId();
        $crawler = $this->browser->request('GET', '/admin/clients/'.$this->acme->getPublicId());

        $form = $crawler->filter(sprintf('form[action="%s"]', $path))->form([
            $formName.'[displayName]' => '陳小姐',
            $formName.'[email]' => $this->globexUser->getEmail(),
        ]);
        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('這個電子郵件已被使用。', (string) $this->browser->getResponse()->getContent());
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $unchanged = $manager->getRepository(User::class)->findOneBy([
            'publicId' => $this->acmeUser->getPublicId(),
        ]);
        self::assertInstanceOf(User::class, $unchanged);
        self::assertSame('client@acme.test', $unchanged->getEmail());
        self::assertNull($manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'user.updated']));
    }

    public function testUserEditRouteCannotCrossClientBoundary(): void
    {
        $this->browser->request('POST', sprintf(
            '/admin/clients/%s/users/%s/edit',
            $this->acme->getPublicId(),
            $this->globexUser->getPublicId(),
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDuplicateActiveCredentialNameIsAValidationErrorInsteadOfServerFailure(): void
    {
        $credential = new ApiCredential(
            $this->acme,
            'ERP Sync',
            'existing1',
            'ptk_existing1.demo',
            str_repeat('a', 64),
            $this->admin,
        );
        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        $path = '/admin/clients/'.$this->acme->getPublicId().'/credentials';
        $crawler = $this->browser->request('GET', '/admin/clients/'.$this->acme->getPublicId());
        $form = $crawler->filter(sprintf('form[action="%s"]', $path))->form([
            'credential_create[name]' => 'erp sync',
        ]);
        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('這個整合名稱已有啟用中的憑證。', (string) $this->browser->getResponse()->getContent());
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $manager->getRepository(ApiCredential::class)->count([]));
        self::assertNull($manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'credential.created']));
    }

    public function testClientIndexUsesAConstantNumberOfQueriesAsClientCountGrows(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->entityManager->persist(new Client('客戶 '.sprintf('%02d', $i), 'client-'.sprintf('%02d', $i)));
        }
        $this->entityManager->flush();

        $queries = static::getContainer()->get(BacktraceDebugDataHolder::class);
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $queries);
        $queries->reset();
        $this->browser->request('GET', '/admin/clients');

        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(7, count($queries->getData()['default'] ?? []));
    }
}
