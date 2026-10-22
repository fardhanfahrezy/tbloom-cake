<?php

namespace App\Models;

enum RefundTransferStatus: string
{
    case Prepared = 'prepared';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case FailedConfirmed = 'failed_confirmed';
}
