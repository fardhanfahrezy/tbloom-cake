<?php

use App\CheckoutDetails;
use App\Http\Requests\CheckoutRequest;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['checkout.flavors' => ['sample_flavor' => 'Rasa sintetis'], 'checkout.fillings' => ['sample_filling' => 'Filling sintetis']]);
});

function checkoutDetailsInput(array $changes = []): array
{
    return array_replace(['product_id' => 1, 'product_variant_id' => 2, 'quantity' => 1,
        'flavor_code' => 'sample_flavor', 'filling_code' => 'sample_filling', 'cover_color' => 'Putih',
        'inscription_choice' => 'none', 'inscription' => '', 'pickup_date' => '2026-10-23',
        'pickup_time' => '09:00', 'pickup_method' => 'self_pickup', 'idempotency_key' => 'checkout-test-1'], $changes);
}

function validateCheckoutDetails(array $input, string $instant = '2026-10-22 09:00:00'): array
{
    return app(CheckoutDetails::class)->validate($input, CarbonImmutable::parse($instant, 'UTC'));
}

test('normalizes checkout details and derives labels without accepting browser totals or snapshots', function () {
    $data = validateCheckoutDetails(checkoutDetailsInput(['inscription_choice' => 'text', 'inscription' => "  Selamat\tulang\nhari  ",
        'quantity' => '2', 'unit_price' => 1, 'status' => 'paid', 'flavor_label_snapshot' => 'Palsu']));

    expect($data['inscription'])->toBe('Selamat ulang hari');
    expect($data['quantity'])->toBe(2);
    expect($data['pickup_time'])->toBe('09:00:00');
    expect($data['flavor_label_snapshot'])->toBe('Rasa sintetis');
    expect($data['filling_label_snapshot'])->toBe('Filling sintetis');
    expect($data)->not->toHaveKeys(['unit_price', 'status']);
});

test('rechecks the Jakarta calendar using the supplied decision instant', function (string $instant, string $date, bool $valid) {
    if (! $valid) {
        expect(fn () => validateCheckoutDetails(checkoutDetailsInput(['pickup_date' => $date]), $instant))->toThrow(ValidationException::class);

        return;
    }
    expect(validateCheckoutDetails(checkoutDetailsInput(['pickup_date' => $date]), $instant)['pickup_date'])->toBe($date);
})->with([
    ['2026-10-22 10:00:00.999999', '2026-10-23', true],
    ['2026-10-22 10:00:01', '2026-10-23', false],
    ['2026-10-22 10:00:01', '2026-10-24', true],
    ['2026-10-22 16:59:59', '2026-10-24', true],
    ['2026-10-22 17:00:00', '2026-10-24', true],
    ['2026-10-22 17:00:00', '2026-10-23', false],
    ['2026-10-22 09:00:00', '2027-01-20', true],
    ['2026-10-22 09:00:00', '2027-01-21', false],
]);

test('accepts pickup minute boundaries and an explicit choice without inscription', function (string $time) {
    $data = validateCheckoutDetails(checkoutDetailsInput(['pickup_time' => $time]));
    expect($data['pickup_time'])->toBe($time.':00');
    expect($data['inscription'])->toBe('');
    expect($data['policy_version'])->toBeNull();
    expect($data['courier_terms_accepted_at'])->toBeNull();
})->with(['09:00', '09:01', '20:59', '21:00']);

test('rejects invalid checkout field values with field feedback', function (array $changes, string $field) {
    try {
        validateCheckoutDetails(checkoutDetailsInput($changes));
        $this->fail('Invalid checkout details were accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
        expect($exception->errors()[$field][0])->toBeString()->not->toBeEmpty();
    }
})->with([
    [['pickup_time' => '08:59'], 'pickup_time'], [['pickup_time' => '21:01'], 'pickup_time'],
    [['pickup_time' => '09:00:01'], 'pickup_time'], [['pickup_time' => '9:00'], 'pickup_time'],
    [['pickup_time' => ['09:00']], 'pickup_time'], [['pickup_date' => '2026-02-30'], 'pickup_date'],
    [['pickup_method' => 'delivery'], 'pickup_method'], [['quantity' => 0], 'quantity'],
    [['quantity' => '1.5'], 'quantity'], [['quantity' => 2147483648], 'quantity'],
    [['product_id' => 0], 'product_id'], [['product_variant_id' => []], 'product_variant_id'],
    [['flavor_code' => 'unknown'], 'flavor_code'], [['filling_code' => 'unknown'], 'filling_code'],
    [['flavor_code' => ['sample_flavor']], 'flavor_code'], [['filling_code' => ['sample_filling']], 'filling_code'],
    [['cover_color' => ''], 'cover_color'], [['cover_color' => str_repeat('a', 101)], 'cover_color'],
    [['handover_note' => str_repeat('a', 256)], 'handover_note'], [['idempotency_key' => ''], 'idempotency_key'],
    [['idempotency_key' => str_repeat('a', 129)], 'idempotency_key'], [['idempotency_key' => 'key with spaces'], 'idempotency_key'],
    [['inscription_choice' => null], 'inscription_choice'], [['inscription_choice' => 'text', 'inscription' => ''], 'inscription'],
    [['inscription_choice' => 'none', 'inscription' => 'conflict'], 'inscription'],
    [['inscription_choice' => 'text', 'inscription' => str_repeat('kata ', 11)], 'inscription'],
    [['inscription_choice' => 'text', 'inscription' => str_repeat('a', 151)], 'inscription'],
    [['pickup_method' => 'online_courier'], 'courier_terms_accepted'],
    [['pickup_method' => 'online_courier', 'courier_terms_accepted' => false], 'courier_terms_accepted'],
]);

test('counts Unicode whitespace as word boundaries and accepts ten words', function () {
    $text = implode("\u{00A0}", array_fill(0, 10, 'kata'));
    expect(validateCheckoutDetails(checkoutDetailsInput(['inscription_choice' => 'text', 'inscription' => $text]))['inscription'])
        ->toBe('kata kata kata kata kata kata kata kata kata kata');
    expect(fn () => validateCheckoutDetails(checkoutDetailsInput(['inscription_choice' => 'text', 'inscription' => $text.' kata'])))
        ->toThrow(ValidationException::class);
});

test('records courier consent using only server policy and the decision instant', function () {
    $data = validateCheckoutDetails(checkoutDetailsInput(['pickup_method' => 'online_courier', 'courier_terms_accepted' => 'on',
        'policy_version' => 'forged', 'policy_text_snapshot' => 'forged', 'courier_terms_accepted_at' => '2000-01-01']));
    expect($data['policy_version'])->toBe('courier-v1');
    expect($data['policy_text_snapshot'])->toBe('Pemesanan kurir dan ongkir menjadi tanggung jawab customer. Kerusakan setelah cake diserahkan ke kurir bukan tanggung jawab TBloom Cake.');
    expect($data['courier_terms_accepted_at'])->toBe('2026-10-22 09:00:00');
});

test('fails closed when official flavor or filling configuration is empty', function (string $key, string $field) {
    config([$key => []]);
    try {
        validateCheckoutDetails(checkoutDetailsInput());
        $this->fail('An unconfigured option was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([['checkout.flavors', 'flavor_code'], ['checkout.fillings', 'filling_code']]);

test('applies checkout request validation to active customers only', function (string $kind, int $status) {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'UTC'));
    Route::post('/_checkout-details-probe', fn (CheckoutRequest $request) => response()->json($request->validated()))->middleware('auth');
    if ($kind !== 'guest') {
        $this->actingAs(User::factory()->make(['id' => 1, 'role' => match ($kind) {
            'admin' => UserRole::Admin, 'owner' => UserRole::Owner, default => UserRole::Customer
        }, 'is_active' => $kind !== 'inactive']));
    }
    $response = $this->postJson('/_checkout-details-probe', checkoutDetailsInput());
    $response->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('pickup_date', '2026-10-23');
        $this->postJson('/_checkout-details-probe', checkoutDetailsInput(['pickup_time' => '08:59']))
            ->assertUnprocessable()->assertJsonValidationErrors('pickup_time');
    }
})->with([['guest', 401], ['customer', 200], ['admin', 403], ['owner', 403], ['inactive', 403]]);

test('accepts the character and handover note limits independently of word limits', function () {
    $data = validateCheckoutDetails(checkoutDetailsInput(['inscription_choice' => 'text', 'inscription' => str_repeat('a', 150), 'handover_note' => str_repeat('b', 255)]));
    expect($data['inscription'])->toHaveLength(150);
    expect($data['handover_note'])->toHaveLength(255);
});

test('rejects missing required choices and malformed inscription bytes', function () {
    try {
        validateCheckoutDetails([]);
        $this->fail('Missing checkout details were accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['product_id', 'product_variant_id', 'quantity', 'flavor_code', 'filling_code', 'cover_color', 'inscription_choice', 'pickup_date', 'pickup_time', 'pickup_method', 'idempotency_key']);
    }
    expect(fn () => validateCheckoutDetails(checkoutDetailsInput(['inscription_choice' => 'text', 'inscription' => "\xFF"])))
        ->toThrow(ValidationException::class);
});
