<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Service\TraceIdProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TraceIdProvider $traceIds,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== 'main' || !$event->getUser() instanceof User) {
            return;
        }

        $user = $event->getUser();
        $this->record(new AuditEvent(
            action: 'authentication.succeeded',
            subjectType: 'user_session',
            traceId: $this->traceIds->current(),
            actorType: AuditActorType::User,
            client: $user->getClient(),
            actor: $user,
            subjectPublicId: $user->getPublicId(),
        ));
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== 'main') {
            return;
        }

        $this->record(new AuditEvent(
            action: 'authentication.failed',
            subjectType: 'user_session',
            traceId: $this->traceIds->current(),
            actorType: AuditActorType::Anonymous,
        ));
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->record(new AuditEvent(
            action: 'authentication.logged_out',
            subjectType: 'user_session',
            traceId: $this->traceIds->current(),
            actorType: AuditActorType::User,
            client: $user->getClient(),
            actor: $user,
            subjectPublicId: $user->getPublicId(),
        ));
    }

    private function record(AuditEvent $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
