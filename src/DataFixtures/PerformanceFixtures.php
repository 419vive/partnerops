<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class PerformanceFixtures extends Fixture implements FixtureGroupInterface
{
    private const REQUEST_COUNT = 10000;
    private const PUBLIC_ID_PREFIX = '01KPERF000';

    public static function getGroups(): array
    {
        return ['performance'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Performance fixtures require Doctrine ORM.');
        }

        $connection = $manager->getConnection();
        $existing = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM service_request WHERE public_id LIKE :prefix',
            ['prefix' => self::PUBLIC_ID_PREFIX.'%'],
        );
        if ($existing === self::REQUEST_COUNT) {
            return;
        }
        if ($existing !== 0) {
            throw new \RuntimeException(sprintf(
                'Found %d partial performance requests; reload the disposable database before retrying.',
                $existing,
            ));
        }

        $clientId = $connection->fetchOne("SELECT id FROM client WHERE slug = 'acme' AND is_archived = false");
        $agentId = $connection->fetchOne("SELECT id FROM app_user WHERE email = 'agent@partnerops.test' AND is_active = true");
        if ($clientId === false || $agentId === false) {
            throw new \RuntimeException('Load the deterministic demo fixtures before the performance group.');
        }

        $connection->transactional(function (Connection $connection) use ($clientId, $agentId): void {
            $rows = [];
            $params = [];

            for ($sequence = 1; $sequence <= self::REQUEST_COUNT; ++$sequence) {
                $status = ['new', 'in_progress', 'waiting_client', 'resolved', 'closed'][$sequence % 5];
                $priority = ['low', 'normal', 'high', 'urgent'][$sequence % 4];
                $createdAt = (new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')))
                    ->modify(sprintf('+%d seconds', $sequence))
                    ->format('Y-m-d H:i:s');
                $dueAt = $sequence % 3 === 0
                    ? null
                    : sprintf('2026-07-%02d 09:00:00', ($sequence % 28) + 1);

                $rows[] = '(?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
                array_push(
                    $params,
                    self::PUBLIC_ID_PREFIX.str_pad((string) $sequence, 16, '0', STR_PAD_LEFT),
                    $clientId,
                    $agentId,
                    $sequence % 2 === 0 ? $agentId : null,
                    '效能測試請求 '.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                    '用於驗證大量資料下的儀表板與篩選清單查詢效能。',
                    $priority,
                    $status,
                    $dueAt,
                    $status === 'resolved' ? $createdAt : null,
                    $status === 'closed' ? $createdAt : null,
                    $createdAt,
                    $createdAt,
                );

                if (count($rows) === 500 || $sequence === self::REQUEST_COUNT) {
                    $connection->executeStatement(
                        'INSERT INTO service_request '
                        .'(public_id, client_id, requester_id, created_by_credential_id, assignee_id, title, description, priority, status, due_at, resolved_at, closed_at, version, created_at, updated_at) VALUES '
                        .implode(', ', $rows),
                        $params,
                    );
                    $rows = [];
                    $params = [];
                }
            }
        });
    }
}
