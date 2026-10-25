<?php

use App\BankTransferObservation;
use App\BankTransferVerifier;
use App\ReconciledPaymentTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function bankTransferFixtureA(): array
{
    return [
        'order_id' => 'TBLOOM-GPAY-d7abb57a-5f07-445b-803d-bd2c46708747',
        'transaction_id' => '87976604-1682-4266-a423-8934ed0da5c5',
        'gross_amount' => '400000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'status_code' => '200', 'transaction_status' => 'settlement', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-24 22:30:03', 'settlement_time' => '2026-10-24 22:31:45',
        'expiry_time' => '2026-10-24 22:59:19',
    ];
}

function verifyBankTransferFixture(mixed $payload, ?int $httpStatus = 200, ?ReconciledPaymentTime $time = null): BankTransferObservation
{
    return (new BankTransferVerifier)->verify(
        httpStatus: $httpStatus, payload: $payload,
        expectedReference: 'TBLOOM-GPAY-d7abb57a-5f07-445b-803d-bd2c46708747', expectedAmount: 400000,
        deadline: new DateTimeImmutable('2026-10-24 22:59:19+07:00'),
        expectedTransactionId: '87976604-1682-4266-a423-8934ed0da5c5', reconciledTime: $time,
    );
}

test('normalizes verified BCA success without inventing the payment action time', function () {
    $result = verifyBankTransferFixture(bankTransferFixtureA());

    expect($result->outcome->value)->toBe('success_observed')
        ->and($result->transactionId)->toBe('87976604-1682-4266-a423-8934ed0da5c5')
        ->and($result->amount)->toBe(400000)->and($result->bank)->toBe('bca')
        ->and($result->transactionTime?->format(DATE_ATOM))->toBe('2026-10-24T15:30:03+00:00')
        ->and($result->settlementTime?->format(DATE_ATOM))->toBe('2026-10-24T15:31:45+00:00')
        ->and($result->timing->value)->toBe('unknown')->and($result->actionTime)->toBeNull();
});

test('normalizes verified BCA expiry as an observation without releasing capacity', function () {
    $payload = [
        'order_id' => 'TBLOOM-GPAY-116460e9-375b-4996-98a5-d1d35b80926b',
        'transaction_id' => '2967a077-17a7-4d5d-93ec-7cca66edd379',
        'gross_amount' => '500000.00', 'currency' => 'IDR', 'payment_type' => 'bank_transfer',
        'status_code' => '407', 'transaction_status' => 'expire', 'va_numbers' => [['bank' => 'bca']],
        'transaction_time' => '2026-10-24 22:30:03', 'expiry_time' => '2026-10-24 22:34:25',
    ];
    $result = (new BankTransferVerifier)->verify(200, $payload,
        'TBLOOM-GPAY-116460e9-375b-4996-98a5-d1d35b80926b', 500000,
        new DateTimeImmutable('2026-10-24 22:59:25+07:00'), '2967a077-17a7-4d5d-93ec-7cca66edd379');

    expect($result->outcome->value)->toBe('expired_observed')->and($result->actionTime)->toBeNull()
        ->and($result->expiryTime?->format(DATE_ATOM))->toBe('2026-10-24T15:34:25+00:00');
});

test('transport failures and not found remain unknown', function (?int $httpStatus) {
    $result = verifyBankTransferFixture(bankTransferFixtureA(), $httpStatus);

    expect($result->outcome->value)->toBe('unknown')->and($result->timing->value)->toBe('unknown')
        ->and($result->actionTime)->toBeNull();
})->with([null, 404, 401, 429, 500, 302, 407]);

test('pending remains pending after provider expiry and business deadline', function () {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), [
        'status_code' => '201', 'transaction_status' => 'pending', 'settlement_time' => null,
        'expiry_time' => '2026-10-24 22:00:00', 'received_at' => '2026-10-25 08:00:00',
    ]));

    expect($result->outcome->value)->toBe('pending')->and($result->timing->value)->toBe('unknown');
});

test('only transaction bound reconciled evidence classifies timely and late success', function (string $paidAt, string $expectedTiming) {
    $time = ReconciledPaymentTime::fromReconciliation('87976604-1682-4266-a423-8934ed0da5c5',
        new DateTimeImmutable($paidAt), 'synthetic-bank-confirmation-1');
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), [
        'settlement_time' => '2026-10-24 23:01:00', 'received_at' => '2026-10-25 08:00:00',
    ]), time: $time);

    expect($result->outcome->value)->toBe('success_observed')->and($result->timing->value)->toBe($expectedTiming)
        ->and($result->actionTime?->evidenceReference)->toBe('synthetic-bank-confirmation-1');
})->with([
    ['2026-10-24 22:59:18+07:00', 'timely'], ['2026-10-24 15:59:18+00:00', 'timely'],
    ['2026-10-24 22:59:19+07:00', 'unknown'], ['2026-10-24 22:59:20+07:00', 'late'],
]);

test('normalization has no HTTP or database side effects and does not return secrets', function () {
    Http::preventStrayRequests();
    Http::fake();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $payload = array_replace(bankTransferFixtureA(), [
        'signature_key' => 'private-signature', 'customer_details' => ['email' => 'private@example.test'],
        'va_numbers' => [['bank' => 'bca', 'va_number' => 'private-va-number']],
        'paid_at' => '2026-10-24 22:31:45', 'verified' => true,
    ]);

    $result = verifyBankTransferFixture($payload);

    expect(json_encode($result))->not->toContain('private-signature', 'private@example.test', 'private-va-number')
        ->and($result->timing->value)->toBe('unknown')->and($queries)->toBe([]);
    Http::assertNothingSent();
});

test('invalid provider bodies and error codes remain unknown', function (mixed $payload) {
    $result = verifyBankTransferFixture($payload);

    expect($result->outcome->value)->toBe('unknown')->and($result->actionTime)->toBeNull();
})->with([[null], ['<html>error</html>'], [123], [[]], [['status_code' => '404']], [['status_code' => 200]]]);

test('missing required payment fields never become success', function (string $field) {
    $payload = bankTransferFixtureA();
    unset($payload[$field]);

    $result = verifyBankTransferFixture($payload);

    expect($result->outcome->value)->toBe('unknown');
})->with(['order_id', 'transaction_id', 'gross_amount', 'currency', 'payment_type', 'transaction_status', 'transaction_time']);

test('conflicting payment identity or money is isolated from valid success', function (array $overrides) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), $overrides));

    expect($result->outcome->value)->toBe('conflict')->and($result->timing->value)->toBe('unknown')
        ->and($result->transactionId)->toBeNull()->and($result->amount)->toBeNull();
})->with([
    [['order_id' => 'OTHER-ORDER']],
    [['transaction_id' => '2967a077-17a7-4d5d-93ec-7cca66edd379']],
    [['transaction_id' => '87976604-1682-4266-A423-8934ED0DA5C5']],
    [['gross_amount' => '400001.00']], [['gross_amount' => '0.00']],
    [['gross_amount' => '999999999999999999999999999999999999.00']],
    [['currency' => 'USD']], [['payment_type' => 'credit_card']],
]);

test('malformed payment fields are not coerced into verified facts', function (array $overrides) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), $overrides));

    expect($result->outcome->value)->toBe('unknown')->and($result->amount)->toBeNull();
})->with([
    [['transaction_id' => 'not-a-uuid']], [['transaction_id' => ['private-value']]],
    [['gross_amount' => 400000]], [['gross_amount' => 400000.0]], [['gross_amount' => '4e5']],
    [['gross_amount' => '400000.01']], [['gross_amount' => '0400000.00']], [['gross_amount' => '-400000.00']],
]);

test('unknown or ambiguous bank details remain unsupported', function (mixed $accounts) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), ['va_numbers' => $accounts]));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('unsupported_bank');
})->with([[null], [[]], ['bca'], [[['bank' => 'bni']]], [[['bank' => 'bca'], ['bank' => 'bca']]], [['bank' => 'bca']], [[null]]]);

test('malformed timestamp fields remain unknown without throwing', function (string $field, mixed $value) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), [$field => $value]));

    expect($result->outcome->value)->toBe('unknown')->and($result->reason)->toBe('malformed_timestamp');
})->with([
    ['transaction_time', null], ['transaction_time', '2026-02-30 22:30:03'],
    ['settlement_time', ['private-date']], ['settlement_time', '2026-10-24T22:31:45+07:00'],
    ['expiry_time', "2026-10-24 22:59:19\0"], ['expiry_time', '2026-10-24 22:59:60'],
]);

test('contradictory provider codes are not accepted as terminal facts', function (string $code, string $status) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), ['status_code' => $code, 'transaction_status' => $status]));

    expect($result->outcome->value)->toBe('unknown')->and($result->actionTime)->toBeNull();
})->with([['407', 'settlement'], ['201', 'settlement'], ['200', 'expire'], ['200', 'capture'], ['200', 'refund'], ['202', 'expire']]);

test('success timestamps conflicting with pending expiry or chronology require review', function (string $status, string $code, string $settlement) {
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), [
        'transaction_status' => $status, 'status_code' => $code, 'settlement_time' => $settlement,
    ]));

    expect($result->outcome->value)->toBe('conflict')->and($result->reason)->toBe('status_time_conflict');
})->with([
    ['pending', '201', '2026-10-24 22:31:45'], ['expire', '407', '2026-10-24 22:31:45'],
    ['settlement', '200', '2026-10-24 22:30:02'],
]);

test('reconciled time cannot be reused for another transaction or contradict provider facts', function (string $transactionId, string $paidAt, string $status, string $code) {
    $time = ReconciledPaymentTime::fromReconciliation($transactionId, new DateTimeImmutable($paidAt), 'synthetic-confirmation');
    $result = verifyBankTransferFixture(array_replace(bankTransferFixtureA(), [
        'transaction_status' => $status, 'status_code' => $code,
        'settlement_time' => $status === 'settlement' ? '2026-10-24 22:31:45' : null,
    ]), time: $time);

    expect($result->outcome->value)->toBe('conflict')->and($result->timing->value)->toBe('unknown');
})->with([
    ['2967a077-17a7-4d5d-93ec-7cca66edd379', '2026-10-24 22:31:00+07:00', 'settlement', '200'],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:30:02+07:00', 'settlement', '200'],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:46+07:00', 'settlement', '200'],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:00+07:00', 'pending', '201'],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:00+07:00', 'expire', '407'],
]);

test('replay preserves facts and input and does not depend on handler arrival time', function () {
    $payload = bankTransferFixtureA();
    $original = $payload;
    $first = verifyBankTransferFixture($payload);
    $second = verifyBankTransferFixture(array_replace($payload, ['received_at' => '2026-10-25 23:59:00', 'paid_at' => '2026-10-24 22:00:00']));

    expect($second)->toEqual($first)->and($payload)->toBe($original)
        ->and($first->timing->value)->toBe('unknown');
});

test('missing settlement time and first discovery do not invent timing', function () {
    $payload = bankTransferFixtureA();
    unset($payload['settlement_time']);
    $payload['gross_amount'] = '400000';
    $result = (new BankTransferVerifier)->verify(200, $payload,
        'TBLOOM-GPAY-d7abb57a-5f07-445b-803d-bd2c46708747', 400000,
        new DateTimeImmutable('2026-10-24 15:59:19+00:00'));

    expect($result->outcome->value)->toBe('success_observed')->and($result->timing->value)->toBe('unknown')
        ->and($result->transactionId)->toBe('87976604-1682-4266-a423-8934ed0da5c5');
});

test('invalid expected context fails before normalizing any provider data', function (string $reference, int $amount, string $deadline, ?string $transactionId) {
    expect(fn () => (new BankTransferVerifier)->verify(200, bankTransferFixtureA(), $reference, $amount, new DateTimeImmutable($deadline), $transactionId))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['', 400000, '2026-10-24 22:59:19+07:00', null],
    ["private\nreference", 400000, '2026-10-24 22:59:19+07:00', null],
    ['A', 0, '2026-10-24 22:59:19+07:00', null],
    ['A', -1, '2026-10-24 22:59:19+07:00', null],
    ['A', 400000, '2026-10-24 22:59:19.000001+07:00', null],
    ['A', 400000, '2026-10-24 22:59:19+07:00', 'wrong-transaction'],
]);

test('reconciled evidence requires a valid identity reference and explicit precision', function (string $id, string $time, string $reference) {
    expect(fn () => ReconciledPaymentTime::fromReconciliation($id, new DateTimeImmutable($time), $reference))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['bad-id', '2026-10-24 22:31:00+07:00', 'evidence-1'],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:00+07:00', ''],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:00+07:00', "evidence\n1"],
    ['87976604-1682-4266-a423-8934ed0da5c5', '2026-10-24 22:31:00.000001+07:00', 'evidence-1'],
]);
