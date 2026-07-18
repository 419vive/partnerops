<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\AllowancePeriodRepository;
use App\Repository\ServiceRequestRepository;
use App\Repository\TimeEntryRepository;
use App\Service\AllowanceCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ServiceRequestRepository $serviceRequests,
        private readonly AllowancePeriodRepository $allowancePeriods,
        private readonly TimeEntryRepository $timeEntries,
        private readonly AllowanceCalculator $allowanceCalculator,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today = self::taipeiCalendarDate($now);
        $client = $user->getRole() === UserRole::Client ? $user->getClient() : null;
        $allowance = $client === null ? null : $this->allowancePeriods->findApplicable($client, $today);
        $allowanceSummary = $allowance === null ? null : $this->allowanceCalculator->summarize(
            $allowance,
            $this->timeEntries->sumApprovedMinutes($allowance),
        );

        return $this->render('dashboard/index.html.twig', [
            'counts' => $this->serviceRequests->dashboardCounts($now, $now->modify('+7 days'), $client),
            'queue' => $this->serviceRequests->findPrioritizedQueue(12, $client),
            'allowance' => $allowance,
            'allowanceSummary' => $allowanceSummary,
            'overBudgetCount' => $this->allowancePeriods->countCurrentOverBudget($today, $client),
            'now' => $now,
        ]);
    }

    private static function taipeiCalendarDate(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $date = $instant->setTimezone(new \DateTimeZone('Asia/Taipei'))->format('Y-m-d');

        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }
}
