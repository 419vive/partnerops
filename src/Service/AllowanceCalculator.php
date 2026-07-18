<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AllowancePeriod;

final class AllowanceCalculator
{
    /**
     * @return array{includedMinutes:int, approvedUsedMinutes:int, remainingMinutes:int, overageMinutes:int, utilizationPercent:float}
     */
    public function summarize(AllowancePeriod $period, int $approvedUsedMinutes): array
    {
        if ($approvedUsedMinutes < 0) {
            throw new \InvalidArgumentException('Approved usage cannot be negative.');
        }

        $included = $period->getIncludedMinutes();

        return [
            'includedMinutes' => $included,
            'approvedUsedMinutes' => $approvedUsedMinutes,
            'remainingMinutes' => max($included - $approvedUsedMinutes, 0),
            'overageMinutes' => max($approvedUsedMinutes - $included, 0),
            'utilizationPercent' => $approvedUsedMinutes / $included * 100,
        ];
    }
}
