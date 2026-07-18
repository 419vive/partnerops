<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditActorType: string
{
    case User = 'user';
    case ApiCredential = 'api_credential';
    case System = 'system';
    case Anonymous = 'anonymous';
}
