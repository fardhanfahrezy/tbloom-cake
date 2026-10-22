<?php

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestStatus;
use Database\Seeders\CustomCakeRequestTestSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('links the private custom request to its customer and preserves contact snapshots', function () {
    $request = CustomCakeRequest::factory()->create()->fresh();
    expect($request->user->customCakeRequests->sole()->is($request))->toBeTrue();
    expect($request->customer_name_snapshot)->toBe($request->user->name);
    expect($request->whatsapp_snapshot)->toBe($request->user->whatsapp_number);
    expect($request->status)->toBe(CustomCakeRequestStatus::Submitted);
    expect($request->edible_print)->toBeFalse();
    expect($request->fondant)->toBeFalse();
    expect($request->option_labels_snapshot)->toBe(['flavor' => '[TEST] Vanilla', 'filling' => '[TEST] Cream']);
    $name = $request->customer_name_snapshot;
    $whatsapp = $request->whatsapp_snapshot;
    $request->user->update(['name' => '[TEST] Updated customer', 'whatsapp_number' => '+628111111111']);
    expect($request->fresh()->customer_name_snapshot)->toBe($name);
    expect($request->fresh()->whatsapp_snapshot)->toBe($whatsapp);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('daily_availabilities', 0);
});

test('represents each custom communication state without financial rows', function (string $state) {
    $request = CustomCakeRequest::factory()->$state()->create()->fresh();
    expect($request->status->value)->toBe($state);
    if ($state !== 'submitted') {
        expect($request->assignedAdmin->role->value)->toBe('admin');
    }
    $this->assertDatabaseCount('payments', 0);
})->with(['submitted', 'contacted', 'quoted', 'accepted', 'rejected']);

test('supports explicit print and fondant fixture options', function () {
    $request = CustomCakeRequest::factory()->withEdiblePrint()->withFondant()->create()->fresh();
    expect($request->edible_print)->toBeTrue();
    expect($request->fondant)->toBeTrue();
    expect($request->fondant_details)->toBe('[TEST] Synthetic fondant decoration');
});

test('rejects invalid custom request rows in MySQL', function (array $overrides, int $code) {
    $attributes = array_replace(CustomCakeRequest::factory()->raw(), [
        'option_labels_snapshot' => json_encode(['flavor' => '[TEST] Vanilla', 'filling' => '[TEST] Cream']),
        'created_at' => '2026-11-01 00:00:00',
        'updated_at' => '2026-11-01 00:00:00',
    ], $overrides);
    try {
        DB::table('custom_cake_requests')->insert($attributes);
        $this->fail('MySQL accepted an invalid custom request.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'invalid status' => [['status' => 'paid'], 3819],
    'status case' => [['status' => 'SUBMITTED'], 3819],
    'status whitespace' => [['status' => 'submitted '], 3819],
    'invalid edible flag' => [['edible_print' => 2], 3819],
    'invalid fondant flag' => [['fondant' => 2], 3819],
    'negative flag' => [['edible_print' => -1], 1264],
    'missing fondant details' => [['fondant' => 1, 'fondant_details' => null], 3819],
    'empty fondant details' => [['fondant' => 1, 'fondant_details' => ''], 3819],
    'blank fondant details' => [['fondant' => 1, 'fondant_details' => '   '], 3819],
    'rejection without reason' => [['status' => 'rejected', 'status_reason' => null], 3819],
    'blank rejection reason' => [['status' => 'rejected', 'status_reason' => '   '], 3819],
    'missing customer' => [['user_id' => 0], 1452],
    'missing assignee' => [['assigned_admin_id' => 0], 1452],
    'name limit' => [['customer_name_snapshot' => str_repeat('a', 101)], 1406],
    'WhatsApp limit' => [['whatsapp_snapshot' => str_repeat('1', 17)], 1406],
    'size limit' => [['size' => str_repeat('a', 101)], 1406],
    'color limit' => [['color' => str_repeat('a', 101)], 1406],
    'inscription limit' => [['inscription' => str_repeat('a', 151)], 1406],
    'notes limit' => [['notes' => str_repeat('a', 2001)], 1406],
    'request number limit' => [['request_number' => str_repeat('a', 41)], 1406],
    'null labels' => [['option_labels_snapshot' => null], 1048],
    'invalid JSON' => [['option_labels_snapshot' => 'not-json'], 3140],
    'null pickup date' => [['pickup_date' => null], 1048],
    'null request key' => [['idempotency_key' => null], 1048],
    'null request hash' => [['request_hash' => null], 1048],
    'null creation' => [['created_at' => null], 1048],
    'null update time' => [['updated_at' => null], 1048],
]);

test('accepts all text limits and nullable optional personalization', function () {
    $request = CustomCakeRequest::factory()->create([
        'customer_name_snapshot' => str_repeat('a', 100),
        'size' => str_repeat('a', 100),
        'color' => str_repeat('a', 100),
        'inscription' => str_repeat('a', 150),
        'notes' => str_repeat('a', 2000),
    ]);
    expect($request->fresh()->notes)->toHaveLength(2000);
    $empty = CustomCakeRequest::factory()->create(['inscription' => null, 'notes' => null, 'fondant_details' => null]);
    expect($empty->fresh()->inscription)->toBeNull();
});

test('deduplicates opaque request keys per customer and numbers globally', function (string $key) {
    $first = CustomCakeRequest::factory()->create();
    $overrides = $key === 'request_number'
        ? ['request_number' => $first->request_number]
        : ['user_id' => $first->user_id, 'idempotency_key' => $first->idempotency_key];
    try {
        CustomCakeRequest::factory()->create($overrides);
        $this->fail('MySQL accepted a duplicate custom request identity.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1062);
    }
    $this->assertDatabaseCount('custom_cake_requests', 1);
})->with(['request_number', 'idempotency_key']);

test('allows the same request key for another customer and retains identifier case', function () {
    $first = CustomCakeRequest::factory()->create(['idempotency_key' => 'Test-Case']);
    CustomCakeRequest::factory()->create(['idempotency_key' => $first->idempotency_key]);
    CustomCakeRequest::factory()->for($first->user)->create(['idempotency_key' => 'test-case']);
    $this->assertDatabaseCount('custom_cake_requests', 3);
});

test('restricts deletion and renumbering of customer and assignee history', function (string $parent, string $operation) {
    $request = CustomCakeRequest::factory()->contacted()->create();
    $id = $parent === 'customer' ? $request->user_id : $request->assigned_admin_id;
    try {
        $target = DB::table('users')->where('id', $id);
        if ($operation === 'delete') {
            $target->delete();
        } else {
            $target->update(['id' => 999999999]);
        }
        $this->fail('MySQL discarded custom request ownership.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($request);
})->with(['customer', 'assignee'])->with(['delete', 'update']);

test('refuses rollback that would discard custom request history', function () {
    $request = CustomCakeRequest::factory()->create();
    $migration = require database_path('migrations/2026_10_22_093653_create_custom_cake_requests_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($request);
});

test('seeds only synthetic communication states in the isolated database', function () {
    $this->seed(CustomCakeRequestTestSeeder::class);
    $this->assertDatabaseCount('custom_cake_requests', 5);
    expect(CustomCakeRequest::query()->orderBy('id')->pluck('status')->map(fn ($status) => $status->value)->all())
        ->toBe(['submitted', 'contacted', 'quoted', 'accepted', 'rejected']);
    $this->assertDatabaseCount('payments', 0);
});

test('refuses custom request seeding outside the isolated environment', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(CustomCakeRequestTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('custom_cake_requests', 0);
})->with(['environment', 'connection']);

test('matches the complete custom request dictionary without financial foreign keys', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'custom_cake_requests' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'request_number', 'user_id', 'customer_name_snapshot', 'whatsapp_snapshot', 'pickup_date', 'size', 'flavor_code', 'filling_code', 'option_labels_snapshot', 'color', 'inscription', 'edible_print', 'fondant', 'fondant_details', 'notes', 'status', 'assigned_admin_id', 'status_reason', 'idempotency_key', 'request_hash', 'created_at', 'updated_at']);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->all())->toBe(['inscription', 'fondant_details', 'notes', 'assigned_admin_id', 'status_reason']);
    expect($columns->firstWhere('COLUMN_NAME', 'pickup_date')->COLUMN_TYPE)->toBe('date');
    expect($columns->firstWhere('COLUMN_NAME', 'notes')->COLUMN_TYPE)->toBe('varchar(2000)');
    expect($columns->firstWhere('COLUMN_NAME', 'status')->COLUMN_DEFAULT)->toBe('submitted');
    expect($columns->firstWhere('COLUMN_NAME', 'edible_print')->COLUMN_DEFAULT)->toBe('0');
    expect($columns->firstWhere('COLUMN_NAME', 'fondant')->COLUMN_DEFAULT)->toBe('0');
    expect($columns->whereNotNull('DATETIME_PRECISION')->pluck('DATETIME_PRECISION')->all())->toBe([0, 0]);
    $foreignKeys = DB::select("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'custom_cake_requests' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME");
    expect(collect($foreignKeys)->pluck('REFERENCED_TABLE_NAME')->all())->toBe(['users', 'users']);
    expect(collect($foreignKeys)->pluck('COLUMN_NAME')->all())->toBe(['assigned_admin_id', 'user_id']);
});
