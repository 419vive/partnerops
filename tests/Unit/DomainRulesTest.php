<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AllowancePeriod;
use App\Entity\ApiCredential;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\RequestStatus;
use App\Enum\UserRole;
use App\Service\AllowanceCalculator;
use PHPUnit\Framework\TestCase;

final class DomainRulesTest extends TestCase
{
    public function testWorkflowAllowsOnlyDocumentedTransitionsAndMaintainsTimestamps(): void
    {
        [$request] = $this->teamRequest();
        $resolvedAt = new \DateTimeImmutable('2026-07-18 08:00:00', new \DateTimeZone('UTC'));

        $request->transitionTo(RequestStatus::InProgress);
        $request->transitionTo(RequestStatus::Resolved, $resolvedAt);
        self::assertEquals($resolvedAt, $request->getResolvedAt());

        $request->transitionTo(RequestStatus::Closed, $resolvedAt->modify('+1 hour'));
        $request->transitionTo(RequestStatus::InProgress, $resolvedAt->modify('+2 hours'));
        self::assertNull($request->getResolvedAt());
        self::assertNull($request->getClosedAt());

        $this->expectException(\DomainException::class);
        $request->transitionTo(RequestStatus::New);
    }

    public function testAllowanceMathCoversZeroExactAndOverage(): void
    {
        [, $client, $admin] = $this->teamRequest();
        $period = new AllowancePeriod(
            $client,
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-31'),
            1200,
            $admin,
        );
        $calculator = new AllowanceCalculator();

        self::assertSame(1200, $calculator->summarize($period, 0)['remainingMinutes']);
        self::assertSame(0, $calculator->summarize($period, 1200)['remainingMinutes']);
        self::assertSame(60, $calculator->summarize($period, 1260)['overageMinutes']);
        self::assertSame(105.0, $calculator->summarize($period, 1260)['utilizationPercent']);
    }

    public function testApiRequestHasCredentialOriginWithoutInventedUser(): void
    {
        [, $client, $admin] = $this->teamRequest();
        $credential = new ApiCredential(
            $client,
            'Test intake',
            'demo01',
            'ptk_demo01.secret',
            str_repeat('a', 64),
            $admin,
        );

        $request = ServiceRequest::fromApi(
            $credential,
            'API request',
            'Created through a client-scoped integration.',
            RequestPriority::High,
        );

        self::assertNull($request->getRequester());
        self::assertSame($credential, $request->getCreatedByCredential());
    }

    public function testClientCommentCannotBecomeInternal(): void
    {
        $client = new Client('Acme', 'acme');
        $contact = new User('client@acme.test', 'hash', 'Acme User', UserRole::Client, $client);
        $request = new ServiceRequest($client, $contact, 'Need support', 'A sufficiently long request body.');

        $comment = new Comment($request, $contact, 'Client-visible note', true);

        self::assertFalse($comment->isInternal());
    }

    /** @return array{ServiceRequest, Client, User} */
    private function teamRequest(): array
    {
        $client = new Client('Acme', 'acme');
        $admin = new User('admin@partnerops.test', 'hash', 'Administrator', UserRole::Admin);

        return [
            new ServiceRequest($client, $admin, 'Investigate issue', 'A sufficiently long request description.'),
            $client,
            $admin,
        ];
    }
}
