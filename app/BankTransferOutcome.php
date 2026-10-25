<?php

namespace App;

enum BankTransferOutcome: string
{
    case SuccessObserved = 'success_observed';
    case ExpiredObserved = 'expired_observed';
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Conflict = 'conflict';
}
