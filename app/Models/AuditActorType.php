<?php

namespace App\Models;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Provider = 'provider';
}
