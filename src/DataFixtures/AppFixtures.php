<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AllowancePeriod;
use App\Entity\ApiCredential;
use App\Entity\AuditEvent;
use App\Entity\Client;
use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Enum\AuditActorType;
use App\Enum\RequestPriority;
use App\Enum\RequestStatus;
use App\Enum\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AppFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'PartnerOps!2026';
    public const ACME_API_TOKEN = 'ptk_demo01.k7s3P2mQ8vN5xR1aC9dF4gH6jL0wY2uB7eT8iO5pZ3A';
    public const GLOBEX_API_TOKEN = 'ptk_demo02.s4F8mN2qR6vK1xC9dH5jL0wY3uB7eT8iO5pZ3A6gQ2W';

    private const PASSWORD_HASH = '$2y$12$rx3SjAzcqcR0I6Vcmnq57uCRxlnwVOIZo2AlRh9k7DPlojTod4iRe';

    public function __construct(
        #[Autowire('%app.api_token_pepper%')]
        private readonly string $apiTokenPepper,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $julyFirst = self::at('2026-07-01 00:00:00');
        $acme = new Client('Acme 電商', 'acme', self::id(1), $julyFirst);
        $globex = new Client('Globex 創意', 'globex', self::id(2), $julyFirst);

        $admin = new User('admin@partnerops.test', self::PASSWORD_HASH, '王管理員', UserRole::Admin, publicId: self::id(3), createdAt: $julyFirst);
        $agent = new User('agent@partnerops.test', self::PASSWORD_HASH, '林顧問', UserRole::Agent, publicId: self::id(4), createdAt: $julyFirst);
        $acmeClient = new User('client@acme.test', self::PASSWORD_HASH, '陳小姐', UserRole::Client, $acme, self::id(5), $julyFirst);
        $globexClient = new User('client@globex.test', self::PASSWORD_HASH, '李先生', UserRole::Client, $globex, self::id(6), $julyFirst);

        $acmeAllowance = new AllowancePeriod(
            $acme,
            self::date('2026-07-01'),
            self::date('2026-07-31'),
            1200,
            $admin,
            self::id(7),
            self::at('2026-07-01 08:00:00'),
        );
        $globexAllowance = new AllowancePeriod(
            $globex,
            self::date('2026-07-01'),
            self::date('2026-07-31'),
            600,
            $admin,
            self::id(8),
            self::at('2026-07-01 08:05:00'),
        );

        $overdue = new ServiceRequest(
            $acme,
            $acmeClient,
            '結帳頁金流間歇失敗',
            '部分客戶在手機版結帳時收到付款失敗，請協助優先查明。',
            RequestPriority::Urgent,
            self::id(9),
            self::at('2026-07-10 02:00:00'),
        );
        $overdue->scheduleFor(self::at('2026-07-16 09:00:00'), self::at('2026-07-10 02:00:00'));

        $active = new ServiceRequest(
            $acme,
            $agent,
            '商品匯入排程調整',
            '需要將每日商品匯入時間改為台灣時間凌晨三點。',
            RequestPriority::High,
            self::id(10),
            self::at('2026-07-11 03:00:00'),
        );
        $active->assignTo($agent, self::at('2026-07-11 03:30:00'));
        $active->scheduleFor(self::at('2026-07-21 10:00:00'), self::at('2026-07-11 03:31:00'));
        $active->transitionTo(RequestStatus::InProgress, self::at('2026-07-11 03:32:00'));

        $globexRequest = new ServiceRequest(
            $globex,
            $globexClient,
            '首頁標題文案更新',
            '請協助將品牌首頁標題更新為七月的新版本文案。',
            RequestPriority::Normal,
            self::id(11),
            self::at('2026-07-12 04:00:00'),
        );
        $globexRequest->assignTo($agent, self::at('2026-07-12 04:30:00'));
        $globexRequest->transitionTo(RequestStatus::WaitingClient, self::at('2026-07-13 05:00:00'));

        $acmeCredential = $this->credential($acme, $admin, 'Acme 快速導覽', self::ACME_API_TOKEN, self::id(12));
        $globexCredential = $this->credential($globex, $admin, 'Globex 快速導覽', self::GLOBEX_API_TOKEN, self::id(13));
        $apiRequest = ServiceRequest::fromApi(
            $acmeCredential,
            'ERP 自動進件測試',
            '由外部整合介面建立的測試進件，用於確認租戶範圍。',
            RequestPriority::Low,
            self::id(14),
            self::at('2026-07-14 06:00:00'),
        );

        $comments = [
            new Comment($overdue, $acmeClient, '上午又有三位客戶遇到相同問題。', false, self::id(15), self::at('2026-07-10 03:00:00')),
            new Comment($overdue, $agent, '已定位到支付網關回應超時，正在比對重試記錄。', true, self::id(16), self::at('2026-07-10 04:00:00')),
            new Comment($active, $agent, '排程設定已在測試環境驗證。', false, self::id(17), self::at('2026-07-12 04:00:00')),
        ];

        $timeEntries = [
            new TimeEntry($overdue, $acmeAllowance, $agent, 300, '查核金流超時與重試記錄', self::date('2026-07-10'), true, publicId: self::id(18), createdAt: self::at('2026-07-10 05:00:00')),
            new TimeEntry($active, $acmeAllowance, $agent, 120, '調整排程並執行回歸驗證', self::date('2026-07-12'), false, publicId: self::id(19), createdAt: self::at('2026-07-12 06:00:00')),
            new TimeEntry($globexRequest, $globexAllowance, $agent, 180, '更新首頁文案並檢查行動版', self::date('2026-07-13'), true, publicId: self::id(20), createdAt: self::at('2026-07-13 06:00:00')),
        ];

        $audits = [
            new AuditEvent('request.created', 'service_request', 'fixture-acme-overdue', AuditActorType::User, $acme, $acmeClient, $overdue->getPublicId(), ['priority' => 'urgent'], self::id(21), self::at('2026-07-10 02:00:00')),
            new AuditEvent('request.status_changed', 'service_request', 'fixture-acme-active', AuditActorType::User, $acme, $agent, $active->getPublicId(), ['from_status' => 'new', 'to_status' => 'in_progress'], self::id(22), self::at('2026-07-11 03:32:00')),
            new AuditEvent('request.created', 'service_request', 'fixture-acme-api', AuditActorType::ApiCredential, $acme, subjectPublicId: $apiRequest->getPublicId(), metadata: ['priority' => 'low'], publicId: self::id(23), occurredAt: self::at('2026-07-14 06:00:00')),
        ];

        foreach ([$acme, $globex, $admin, $agent, $acmeClient, $globexClient, $acmeAllowance, $globexAllowance, $overdue, $active, $globexRequest, $acmeCredential, $globexCredential, $apiRequest, ...$comments, ...$timeEntries, ...$audits] as $entity) {
            $manager->persist($entity);
        }

        $manager->flush();
    }

    private function credential(Client $client, User $admin, string $name, string $token, string $publicId): ApiCredential
    {
        [$head, $secret] = explode('.', $token, 2);
        $selector = substr($head, 4);

        return new ApiCredential(
            $client,
            $name,
            $selector,
            substr($token, 0, 24),
            hash_hmac('sha256', $secret, $this->apiTokenPepper),
            $admin,
            $publicId,
            self::at('2026-07-01 08:10:00'),
        );
    }

    private static function id(int $sequence): string
    {
        return sprintf('01J%s%02d', str_repeat('0', 21), $sequence);
    }

    private static function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function date(string $value): \DateTimeImmutable
    {
        return self::at($value.' 00:00:00');
    }
}
