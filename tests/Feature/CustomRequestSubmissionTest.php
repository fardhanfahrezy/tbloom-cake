<?php

use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\PrivateUploadStorage;
use App\SubmitCustomRequest;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:01', 'UTC'));
});

function customSubmissionInput(array $overrides = []): array
{
    return array_replace(['pickup_date' => '2026-11-08', 'size' => '20 cm', 'flavor_code' => 'chocolate',
        'filling_code' => 'strawberry', 'color' => 'Putih', 'inscription_choice' => 'none',
        'edible_print' => false, 'fondant' => false, 'idempotency_key' => 'custom-test-1',
        'attachments' => [UploadedFile::fake()->image('referensi.png', 2, 2)]], $overrides);
}

test('customer submits a durable custom request with server snapshots and no financial rows', function () {
    Storage::fake('private_uploads');
    Http::preventStrayRequests();
    $customer = User::factory()->create();
    User::factory()->admin()->create();
    $this->actingAs($customer)->postJson('/custom-requests', customSubmissionInput([
        'user_id' => 999, 'status' => 'accepted', 'customer_name_snapshot' => 'Spoof', 'product_total' => 1,
    ]))->assertCreated()->assertJsonPath('data.status', 'submitted');

    $custom = CustomCakeRequest::query()->sole();
    expect($custom->customer_name_snapshot)->toBe($customer->name);
    expect($custom->user_id)->toBe($customer->id);
    expect($custom->option_labels_snapshot['flavor'])->toBe('Chocolate (brownies)');
    expect($custom->attachments()->count())->toBe(1);
    Storage::disk('private_uploads')->assertExists($custom->attachments()->sole()->private_path);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 4);
    expect(json_encode(NotificationDelivery::first()->payload_minimal))->not->toContain($customer->whatsapp_number, 'referensi.png');
    Http::assertNothingSent();
});

test('customer can open the custom form while guests cannot submit', function () {
    $this->postJson('/custom-requests', [])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get('/custom-requests/create')->assertOk()
        ->assertSee('Custom Cake')->assertSee('multipart/form-data', false)->assertSee('idempotency_key', false);
});

test('custom submission enforces calendar lead time and inclusive D90 on the server', function (string $date, bool $print, bool $accepted) {
    Storage::fake('private_uploads');
    $response = $this->actingAs(User::factory()->create())->postJson('/custom-requests', customSubmissionInput(['pickup_date' => $date, 'edible_print' => $print]));
    if ($accepted) {
        $response->assertCreated();
        expect(CustomCakeRequest::query()->sole()->pickup_date->toDateString())->toBe($date);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('pickup_date');
        $this->assertDatabaseCount('custom_cake_requests', 0);
        expect(Storage::disk('private_uploads')->allFiles())->toBeEmpty();
    }
})->with([
    ['2026-11-07', false, false], ['2026-11-08', false, true],
    ['2026-11-11', true, false], ['2026-11-12', true, true],
    ['2027-02-03', false, true], ['2027-02-04', false, false],
]);

test('submission rejects incomplete or unsupported specifications', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())->postJson('/custom-requests', customSubmissionInput($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('custom_cake_requests', 0);
})->with([
    [['size' => str_repeat('x', 101)], 'size'],
    [['color' => str_repeat('x', 101)], 'color'],
    [['notes' => str_repeat('x', 2001)], 'notes'],
    [['inscription_choice' => null], 'inscription_choice'],
    [['inscription_choice' => 'text', 'inscription' => '   '], 'inscription'],
    [['inscription_choice' => 'text', 'inscription' => str_repeat('x', 151)], 'inscription'],
    [['inscription' => 'Unexpected'], 'inscription'],
    [['fondant' => true, 'fondant_details' => '  '], 'fondant_details'],
    [['fondant' => false, 'fondant_details' => 'Unexpected'], 'fondant_details'],
    [['edible_print' => 'false'], 'edible_print'],
    [['flavor_code' => 'unknown'], 'flavor_code'],
    [['filling_code' => 'unknown'], 'filling_code'],
    [['idempotency_key' => '../invalid'], 'idempotency_key'],
]);

test('custom inscription accepts more than ten words and persists normalized fondant details', function () {
    Storage::fake('private_uploads');
    $this->actingAs(User::factory()->create())->postJson('/custom-requests', customSubmissionInput([
        'inscription_choice' => 'text', 'inscription' => 'satu dua tiga empat lima enam tujuh delapan sembilan sepuluh sebelas',
        'fondant' => true, 'fondant_details' => '  Bunga   merah  ',
    ]))->assertCreated();
    expect(CustomCakeRequest::query()->sole()->fondant_details)->toBe('Bunga merah');
});

test('exact replay survives terminal status clock and choice changes without reuploading or reemitting', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $input = customSubmissionInput();
    $custom = app(SubmitCustomRequest::class)->create($customer, $input);
    $custom->forceFill(['status' => 'accepted'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-12-25', 'UTC'));
    config(['checkout.flavors' => [], 'checkout.fillings' => []]);
    $customer->forceFill(['name' => 'New name'])->save();
    $reordered = array_reverse($input, true);
    $reordered['edible_print'] = '0';
    $reordered['fondant'] = '0';
    $this->actingAs($customer)->postJson('/custom-requests', $reordered)->assertOk()->assertJsonPath('data.id', $custom->id);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('custom_cake_requests', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('notification_deliveries', 2);
    expect($custom->fresh()->customer_name_snapshot)->not->toBe('New name');
});

test('same idempotency key with changed details or file bytes conflicts', function (bool $changeFile) {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $input = customSubmissionInput();
    app(SubmitCustomRequest::class)->create($customer, $input);
    if ($changeFile) {
        $input['attachments'] = [UploadedFile::fake()->image('referensi.png', 3, 3)];
    } else {
        $input['color'] = 'Merah';
    }
    $this->actingAs($customer)->postJson('/custom-requests', $input)->assertConflict();
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('audit_logs', 1);
})->with([false, true]);

test('staff cannot create custom requests and stale customer activity is rechecked in the domain', function () {
    $this->actingAs(User::factory()->admin()->create())->postJson('/custom-requests', customSubmissionInput())->assertForbidden();
    $customer = User::factory()->create();
    User::query()->whereKey($customer->id)->update(['is_active' => false]);
    expect(fn () => app(SubmitCustomRequest::class)->create($customer, customSubmissionInput()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('custom_cake_requests', 0);
});

test('outbox failure rolls back request metadata and audit but leaves only a private orphan', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    NotificationDelivery::creating(fn () => throw new RuntimeException('Synthetic outbox failure'));
    expect(fn () => app(SubmitCustomRequest::class)->create($customer, customSubmissionInput()))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('custom_cake_requests', 0);
    $this->assertDatabaseCount('custom_cake_request_attachments', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
});

test('staging occurs outside locks and the final transaction rechecks date and account activity', function (bool $deactivate) {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $storage = app(PrivateUploadStorage::class);
    $spy = Mockery::mock(PrivateUploadStorage::class);
    $spy->shouldReceive('store')->once()->andReturnUsing(function ($upload, $purpose) use ($storage, $customer, $deactivate) {
        expect(DB::transactionLevel())->toBe(0);
        $metadata = $storage->store($upload, $purpose);
        if ($deactivate) {
            User::query()->whereKey($customer->id)->update(['is_active' => false]);
        } else {
            $this->travelTo(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'));
        }

        return $metadata;
    });
    app()->instance(PrivateUploadStorage::class, $spy);
    expect(fn () => app(SubmitCustomRequest::class)->create($customer, customSubmissionInput()))
        ->toThrow($deactivate ? AuthorizationException::class : ValidationException::class);
    $this->assertDatabaseCount('custom_cake_requests', 0);
})->with([false, true]);

test('nested caller transactions cannot enclose file staging', function () {
    $customer = User::factory()->create();
    DB::beginTransaction();
    try {
        expect(fn () => app(SubmitCustomRequest::class)->create($customer, customSubmissionInput()))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
});
