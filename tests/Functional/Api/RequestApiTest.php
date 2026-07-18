<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\DataFixtures\AppFixtures;
use App\Entity\AuditEvent;
use App\Entity\Comment;
use App\Entity\IdempotencyRecord;
use App\Entity\ServiceRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RequestApiTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = static::createClient();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $schema = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $fixtures = static::getContainer()->get(AppFixtures::class);
        self::assertInstanceOf(AppFixtures::class, $fixtures);
        $fixtures->load($entityManager);

        $rateLimitCache = static::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimitCache);
        $rateLimitCache->clear();
    }

    public function testAuthenticationFailuresUseProblemDetailsWithoutCredentialHints(): void
    {
        $this->browser->request('GET', '/api/v1/requests/'.self::id(9), server: [
            'HTTP_X_REQUEST_ID' => 'api-auth-test-001',
            'REMOTE_ADDR' => '198.51.100.10',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('www-authenticate', 'Bearer');
        self::assertResponseHeaderSame('x-request-id', 'api-auth-test-001');
        self::assertSame([
            'type' => '/problems/unauthorized',
            'title' => 'Unauthorized',
            'status' => 401,
            'code' => 'unauthorized',
            'detail' => 'Valid authentication is required.',
            'instance' => '/api/v1/requests/'.self::id(9),
            'traceId' => 'api-auth-test-001',
        ], $this->responseJson());

        $invalidToken = preg_replace('/[^.]+$/', str_repeat('A', 43), AppFixtures::ACME_API_TOKEN);
        self::assertIsString($invalidToken);
        $this->browser->request('GET', '/api/v1/requests/'.self::id(9), server: $this->apiHeaders($invalidToken, '198.51.100.11'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('unauthorized', $this->responseJson()['code']);
    }

    public function testCreateAndReplayPersistExactlyOneRequestAuditAndIdempotencyRecord(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $beforeRequests = $entityManager->getRepository(ServiceRequest::class)->count([]);
        $beforeAudits = $entityManager->getRepository(AuditEvent::class)->count(['action' => 'request.created']);
        $payload = [
            'title' => '結帳頁面出現重複訂單',
            'description' => '客戶在手機版送出後收到兩個不同訂單編號，請協助追查。',
            'priority' => 'high',
            'dueAt' => '2026-07-25T10:00:00+08:00',
        ];

        $this->browser->jsonRequest('POST', '/api/v1/requests', $payload, $this->apiHeaders(
            AppFixtures::ACME_API_TOKEN,
            '198.51.100.20',
            'api-create-key-001',
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertResponseHeaderSame('idempotent-replayed', 'false');
        self::assertResponseHeaderSame('ratelimit-limit', '120');
        $firstBody = (string) $this->browser->getResponse()->getContent();
        $created = $this->responseJson();
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $created['id']);
        self::assertSame('high', $created['priority']);
        self::assertSame('new', $created['status']);
        self::assertSame('2026-07-25T02:00:00Z', $created['dueAt']);
        self::assertSame([], $created['comments']);
        self::assertResponseHeaderSame('location', '/api/v1/requests/'.$created['id']);

        for ($replay = 2; $replay <= 20; ++$replay) {
            $this->browser->jsonRequest('POST', '/api/v1/requests', $payload, $this->apiHeaders(
                AppFixtures::ACME_API_TOKEN,
                '198.51.100.20',
                'api-create-key-001',
            ));

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            self::assertResponseHeaderSame('idempotent-replayed', 'true');
            self::assertSame($firstBody, (string) $this->browser->getResponse()->getContent());
        }

        $freshManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($beforeRequests + 1, $freshManager->getRepository(ServiceRequest::class)->count([]));
        self::assertSame($beforeAudits + 1, $freshManager->getRepository(AuditEvent::class)->count(['action' => 'request.created']));
        self::assertSame(1, $freshManager->getRepository(IdempotencyRecord::class)->count([]));
    }

    public function testReusingKeyWithDifferentValidatedPayloadReturnsConflict(): void
    {
        $headers = $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.30', 'api-conflict-key-001');
        $payload = [
            'title' => '外部系統進件測試',
            'description' => '確認同一個冪等鍵不會建立兩筆不同內容的需求。',
            'priority' => 'normal',
        ];
        $this->browser->jsonRequest('POST', '/api/v1/requests', $payload, $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $payload['priority'] = 'urgent';
        $this->browser->jsonRequest('POST', '/api/v1/requests', $payload, $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('idempotency_conflict', $this->responseJson()['code']);
    }

    public function testGetIsClientScopedAndNeverReturnsInternalComments(): void
    {
        $this->browser->request(
            'GET',
            '/api/v1/requests/'.self::id(9),
            server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.40'),
        );

        self::assertResponseIsSuccessful();
        $body = $this->responseJson();
        self::assertCount(1, $body['comments']);
        self::assertSame('上午又有三位客戶遇到相同問題。', $body['comments'][0]['body']);
        self::assertSame(['page' => 1, 'perPage' => 50, 'total' => 1, 'pages' => 1], $body['commentsPagination']);
        self::assertStringNotContainsString('支付網關回應超時', (string) $this->browser->getResponse()->getContent());

        $this->browser->request(
            'GET',
            '/api/v1/requests/'.self::id(11),
            server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.40'),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('not_found', $this->responseJson()['code']);
        self::assertSame('The requested resource was not found.', $this->responseJson()['detail']);
    }

    public function testRequestCommentsAreExplicitlyPaginatedAndOlderRowsRemainRetrievable(): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $request = $manager->getRepository(ServiceRequest::class)->findOneBy(['publicId' => self::id(9)]);
        $author = $manager->getRepository(User::class)->findOneBy(['email' => 'client@acme.test']);
        self::assertInstanceOf(ServiceRequest::class, $request);
        self::assertInstanceOf(User::class, $author);
        $start = new \DateTimeImmutable('2026-08-01 00:00:00', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 55; ++$i) {
            $manager->persist(new Comment(
                $request,
                $author,
                sprintf('Paginated public comment %02d', $i),
                createdAt: $start->modify(sprintf('+%d seconds', $i)),
            ));
        }
        $manager->flush();

        $this->browser->request(
            'GET',
            '/api/v1/requests/'.self::id(9),
            server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.41'),
        );
        self::assertResponseIsSuccessful();
        $firstPage = $this->responseJson();
        self::assertCount(50, $firstPage['comments']);
        self::assertSame(['page' => 1, 'perPage' => 50, 'total' => 56, 'pages' => 2], $firstPage['commentsPagination']);

        $this->browser->request(
            'GET',
            '/api/v1/requests/'.self::id(9).'?commentsPage=2',
            server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.41'),
        );
        self::assertResponseIsSuccessful();
        $secondPage = $this->responseJson();
        self::assertCount(6, $secondPage['comments']);
        self::assertSame(2, $secondPage['commentsPagination']['page']);
        self::assertSame('上午又有三位客戶遇到相同問題。', $secondPage['comments'][0]['body']);
        self::assertStringNotContainsString('支付網關回應超時', (string) $this->browser->getResponse()->getContent());
    }

    public function testValidationRejectsUnknownAndInvalidFieldsWithoutCreatingARequest(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $before = $entityManager->getRepository(ServiceRequest::class)->count([]);

        $this->browser->jsonRequest('POST', '/api/v1/requests', [
            'title' => 'x',
            'description' => 'too short',
            'priority' => 'critical',
            'clientId' => self::id(2),
        ], $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.50', 'api-validation-001'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $problem = $this->responseJson();
        self::assertSame('validation_failed', $problem['code']);
        self::assertSame(['clientId', 'title', 'description', 'priority'], array_column($problem['errors'], 'field'));
        self::assertSame($before, static::getContainer()->get(EntityManagerInterface::class)->getRepository(ServiceRequest::class)->count([]));
    }

    public function testCreationRequiresAnIdempotencyKeyAndValidJson(): void
    {
        $this->browser->jsonRequest('POST', '/api/v1/requests', [
            'title' => '有效但缺少冪等鍵',
            'description' => '這個請求應在解析內容之前就被安全拒絕。',
            'priority' => 'normal',
        ], $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.60'));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_idempotency_key', $this->responseJson()['code']);

        $this->browser->request(
            'POST',
            '/api/v1/requests',
            server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, '198.51.100.60', 'api-invalid-json-001') + [
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{"title":',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_json', $this->responseJson()['code']);
    }

    public function testAnonymousRateLimitReturnsRetryGuidance(): void
    {
        $invalidToken = preg_replace('/[^.]+$/', str_repeat('A', 43), AppFixtures::ACME_API_TOKEN);
        self::assertIsString($invalidToken);
        for ($attempt = 1; $attempt <= 31; ++$attempt) {
            $this->browser->request(
                'GET',
                '/api/v1/requests/'.self::id(9),
                server: $this->apiHeaders($invalidToken, '203.0.113.90'),
            );
        }

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHeaderSame('ratelimit-limit', '30');
        self::assertResponseHeaderSame('ratelimit-remaining', '0');
        self::assertGreaterThanOrEqual(1, (int) $this->browser->getResponse()->headers->get('Retry-After'));
        self::assertSame('rate_limited', $this->responseJson()['code']);
    }

    public function testAuthenticatedTrafficUsesTheCredentialLimitInsteadOfTheAnonymousLimit(): void
    {
        $limitedAt = null;
        for ($attempt = 1; $attempt <= 140; ++$attempt) {
            $ip = sprintf('198.18.%d.%d', intdiv($attempt, 250), ($attempt % 250) + 1);
            $this->browser->request(
                'GET',
                '/api/v1/requests/'.self::id(9),
                server: $this->apiHeaders(AppFixtures::ACME_API_TOKEN, $ip),
            );

            if ($this->browser->getResponse()->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                $limitedAt = $attempt;
                break;
            }

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('ratelimit-limit', '120');
        }

        self::assertNotNull($limitedAt, 'The credential limiter did not reject its burst limit.');
        self::assertGreaterThan(30, $limitedAt);
        self::assertResponseHeaderSame('ratelimit-limit', '120');
        self::assertGreaterThanOrEqual(1, (int) $this->browser->getResponse()->headers->get('Retry-After'));
        self::assertSame('rate_limited', $this->responseJson()['code']);
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, string> */
    private function apiHeaders(string $token, string $ip, ?string $idempotencyKey = null): array
    {
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_REQUEST_ID' => 'api-request-'.substr(hash('sha256', $ip.($idempotencyKey ?? 'get')), 0, 16),
            'REMOTE_ADDR' => $ip,
        ];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $headers;
    }

    private static function id(int $sequence): string
    {
        return sprintf('01J%s%02d', str_repeat('0', 21), $sequence);
    }
}
