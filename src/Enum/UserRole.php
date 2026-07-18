<?php

declare(strict_types=1);

namespace App\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Client = 'client';

    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理員',
            self::Agent => '團隊成員',
            self::Client => '客戶',
        };
    }

    public function canManageWork(): bool
    {
        return $this === self::Admin || $this === self::Agent;
    }
}
