<?php

namespace App;

use Carbon\CarbonImmutable;

class BookingCalendar
{
    /** @return array{earliest: CarbonImmutable, latest: CarbonImmutable} */
    public static function simpleWindow(CarbonImmutable $instant): array
    {
        $local = $instant->setTimezone('Asia/Jakarta')->startOfSecond();
        $today = $local->startOfDay();

        return [
            'earliest' => $today->addDays($local->gt($today->setTime(17, 0)) ? 2 : 1),
            'latest' => $today->addDays(90),
        ];
    }
}
