<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends WebTestCase
{
    public function testLivenessIsDependencyFreeAndReturnsRequestId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_REQUEST_ID' => 'health-check-001']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('cache-control', 'no-store, private');
        self::assertResponseHeaderSame('x-request-id', 'health-check-001');
        self::assertJsonStringEqualsJsonString('{"status":"live"}', (string) $client->getResponse()->getContent());
    }

    public function testReadinessChecksDatabaseWithoutLeakingDetails(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertJsonStringEqualsJsonString('{"status":"ready"}', (string) $client->getResponse()->getContent());
    }

    public function testInvalidRequestIdIsReplaced(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_REQUEST_ID' => "bad\nvalue"]);

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            (string) $client->getResponse()->headers->get('X-Request-ID'),
        );
    }
}
