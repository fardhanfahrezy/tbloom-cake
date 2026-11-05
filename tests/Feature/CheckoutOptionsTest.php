<?php

use App\CheckoutDetails;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

test('accepts all official flavor and filling combinations and snapshots their exact labels', function (string $flavor, string $flavorLabel, string $filling, string $fillingLabel) {
    $data = app(CheckoutDetails::class)->validate([
        'product_id' => 1, 'product_variant_id' => 1, 'quantity' => 1,
        'flavor_code' => $flavor, 'filling_code' => $filling, 'cover_color' => 'Putih',
        'inscription_choice' => 'none', 'pickup_date' => '2026-10-24', 'pickup_time' => '21:00',
        'pickup_method' => 'self_pickup', 'idempotency_key' => 'official-options-1',
    ], CarbonImmutable::parse('2026-10-23 09:00:00', 'UTC'));

    expect($data['flavor_label_snapshot'])->toBe($flavorLabel);
    expect($data['filling_label_snapshot'])->toBe($fillingLabel);
})->with([
    ['chocolate', 'Chocolate (brownies)'], ['vanilla', 'Vanilla (sponge cake)'], ['redvelvet', 'Redvelvet (butter cake)'],
])->with([
    ['strawberry', 'Strawberry jam (selai)'], ['blueberry', 'Blueberry jam (selai)'], ['vanilla', 'Vanilla Cream'],
]);

test('rejects unapproved codes against official configuration', function (string $field, string $value) {
    $input = ['product_id' => 1, 'product_variant_id' => 1, 'quantity' => 1, 'flavor_code' => 'chocolate',
        'filling_code' => 'strawberry', 'cover_color' => 'Putih', 'inscription_choice' => 'none',
        'pickup_date' => '2026-10-24', 'pickup_time' => '09:00', 'pickup_method' => 'self_pickup', 'idempotency_key' => 'official-options-2'];
    $input[$field] = $value;
    try {
        app(CheckoutDetails::class)->validate($input, CarbonImmutable::parse('2026-10-23 09:00:00', 'UTC'));
        $this->fail('Unapproved option was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([['flavor_code', 'sample_flavor'], ['flavor_code', 'Chocolate'], ['filling_code', 'sample_filling'], ['filling_code', 'cream']]);
