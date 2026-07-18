<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case WaitingClient = 'waiting_client';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::New => \in_array($target, [self::InProgress, self::WaitingClient, self::Closed], true),
            self::InProgress => \in_array($target, [self::WaitingClient, self::Resolved], true),
            self::WaitingClient => \in_array($target, [self::InProgress, self::Resolved], true),
            self::Resolved => \in_array($target, [self::InProgress, self::Closed], true),
            self::Closed => $target === self::InProgress,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::New => '新建',
            self::InProgress => '處理中',
            self::WaitingClient => '等待客戶',
            self::Resolved => '已解決',
            self::Closed => '已關閉',
        };
    }
}
