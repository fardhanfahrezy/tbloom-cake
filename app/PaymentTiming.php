<?php

namespace App;

enum PaymentTiming: string
{
    case Unknown = 'unknown';
    case Timely = 'timely';
    case Late = 'late';
}
