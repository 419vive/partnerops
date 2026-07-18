<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticationAuditTest extends WebTestCase
{
    public function testSuccessfulAndFailedBrowserAuthenticationAreAuditedWithoutSubmittedEmail(): void
    {
        $browser = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $admin = new User('admin@example.test', 'pending', '王管理員', UserRole::Admin);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin->changePasswordHash($hasher->hashPassword($admin, 'Commercial!2026'), false);
        $manager->persist($admin);
        $manager->flush();

        $crawler = $browser->request('GET', '/login');
        $form = $crawler->selectButton('進入工作台')->form([
            '_username' => 'nobody@example.test',
            '_password' => 'DefinitelyWrong!2026',
        ]);
        $browser->submit($form);
        self::assertResponseRedirects('/login');
        $browser->followRedirect();
        self::assertStringContainsString('電子郵件或密碼不正確', (string) $browser->getResponse()->getContent());

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $failed = $manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'authentication.failed']);
        self::assertInstanceOf(AuditEvent::class, $failed);
        self::assertSame(AuditActorType::Anonymous, $failed->getActorType());
        self::assertNull($failed->getActor());
        self::assertSame([], $failed->getMetadata());
        self::assertStringNotContainsString('nobody@example.test', json_encode($failed->getMetadata(), JSON_THROW_ON_ERROR));

        $crawler = $browser->request('GET', '/login');
        $form = $crawler->selectButton('進入工作台')->form([
            '_username' => 'admin@example.test',
            '_password' => 'Commercial!2026',
        ]);
        $browser->submit($form);
        self::assertResponseRedirects('/');

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $succeeded = $manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'authentication.succeeded']);
        self::assertInstanceOf(AuditEvent::class, $succeeded);
        self::assertSame($admin->getPublicId(), $succeeded->getSubjectPublicId());
        self::assertSame([], $succeeded->getMetadata());

        $crawler = $browser->followRedirect();
        $browser->submit($crawler->selectButton('登出')->form());
        self::assertResponseRedirects('/login');

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $loggedOut = $manager->getRepository(AuditEvent::class)->findOneBy(['action' => 'authentication.logged_out']);
        self::assertInstanceOf(AuditEvent::class, $loggedOut);
        self::assertSame($admin->getPublicId(), $loggedOut->getSubjectPublicId());
    }
}
