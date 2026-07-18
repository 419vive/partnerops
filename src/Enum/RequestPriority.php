<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => '低',
            self::Normal => '一般',
            self::High => '高',
            self::Urgent => '緊急',
        };
    }
}
