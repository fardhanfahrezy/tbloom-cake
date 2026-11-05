<?php

use App\ReconciledUnpaidClosure;

test('records the final reconciliation context at second precision in UTC', function () {
    $evidence = ReconciledUnpaidClosure::fromFinalReconciliation('123e4567-e89b-42d3-a456-426614174000',
        new DateTimeImmutable('2026-10-28 10:39:00+07:00'), 'private-case-reference');
    expect($evidence->confirmedAt->format('Y-m-d H:i:sP'))->toBe('2026-10-28 03:39:00+00:00');
    expect($evidence->evidenceReference)->toBe('private-case-reference');
});

test('rejects invalid final reconciliation identity reference or precision', function (string $id, string $reference, string $instant) {
    expect(fn () => ReconciledUnpaidClosure::fromFinalReconciliation($id, new DateTimeImmutable($instant), $reference))->toThrow(InvalidArgumentException::class);
})->with([
    ['not-a-transaction', 'proof', '2026-10-28 03:39:00+00:00'],
    ['123e4567-e89b-42d3-a456-426614174000', '', '2026-10-28 03:39:00+00:00'],
    ['123e4567-e89b-42d3-a456-426614174000', "proof\nsecret", '2026-10-28 03:39:00+00:00'],
    ['123e4567-e89b-42d3-a456-426614174000', 'proof', '2026-10-28 03:39:00.123456+00:00'],
]);
