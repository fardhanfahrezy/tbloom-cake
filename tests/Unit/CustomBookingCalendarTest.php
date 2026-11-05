<?php

use App\BookingCalendar;
use Carbon\CarbonImmutable;

test('custom booking uses Jakarta calendar days without an afternoon cutoff', function (string $instant, bool $print, string $earliest, string $latest) {
    $window = BookingCalendar::customWindow(CarbonImmutable::parse($instant), $print);

    expect($window['earliest']->toDateString())->toBe($earliest);
    expect($window['latest']->toDateString())->toBe($latest);
    expect($window['earliest']->timezoneName)->toBe('Asia/Jakarta');
})->with([
    ['2026-11-05T09:59:59Z', false, '2026-11-08', '2027-02-03'],
    ['2026-11-05T10:00:00Z', false, '2026-11-08', '2027-02-03'],
    ['2026-11-05T10:00:01Z', false, '2026-11-08', '2027-02-03'],
    ['2026-11-05T16:59:59Z', true, '2026-11-12', '2027-02-03'],
    ['2026-11-05T17:00:00Z', true, '2026-11-13', '2027-02-04'],
    ['2026-11-05T17:00:00Z', false, '2026-11-09', '2027-02-04'],
    ['2026-12-29T18:00:00Z', true, '2027-01-06', '2027-03-30'],
]);
