<?php

use App\Models\AuditActorType;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuditLogTestSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('R1 schema proof requires phpunit.mysql.xml.');
    }
});

test('preserves redacted actor snapshots and creation-only immutable time', function () {
    $actor = User::factory()->owner()->create();
    $audit = AuditLog::factory()->byUser($actor)->create()->fresh();
    expect($audit->actor->is($actor))->toBeTrue();
    expect($audit->actor_type)->toBe(AuditActorType::User);
    expect($audit->before_redacted)->toBe(['status' => 'pending']);
    expect($audit->after_redacted)->toBe(['status' => 'approved']);
    expect($audit->created_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($audit->getAttributes())->not->toHaveKey('updated_at');
    expect(fn () => AuditLog::create(['action' => 'untrusted']))->toThrow(MassAssignmentException::class);
});

test('accepts system and provider audit without inventing a user', function (string $type) {
    $audit = AuditLog::factory()->create(['actor_type' => $type]);
    expect($audit->actor)->toBeNull();
    expect($audit->actor_type->value)->toBe($type);
})->with(['system', 'provider']);

test('rejects invalid audit rows in MySQL', function (array $overrides, int $code) {
    $attributes = AuditLog::factory()->make()->getAttributes();
    try {
        DB::table('audit_logs')->insert(array_replace($attributes, ['created_at' => '2026-11-01 00:00:00'], $overrides));
        $this->fail('MySQL accepted an invalid audit row.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe($code);
    }
})->with([
    'invalid actor' => [['actor_type' => 'admin'], 3819],
    'uppercase actor' => [['actor_type' => 'SYSTEM'], 3819],
    'actor trailing space' => [['actor_type' => 'system '], 3819],
    'user without FK' => [['actor_type' => 'user', 'actor_user_id' => null], 3819],
    'missing actor' => [['actor_user_id' => 0], 1452],
    'null entity' => [['entity_id' => null], 1048],
    'null action' => [['action' => null], 1048],
    'null correlation' => [['correlation_id' => null], 1048],
    'null created time' => [['created_at' => null], 1048],
    'invalid redacted JSON' => [['after_redacted' => '{bad'], 3140],
    'oversize action' => [['action' => str_repeat('a', 101)], 1406],
]);

test('blocks model instance mutation including quiet arithmetic paths', function (string $operation, bool $persisted) {
    $audit = $persisted ? AuditLog::factory()->create() : AuditLog::factory()->make();
    $original = $audit->entity_id;
    if (! $persisted && in_array($operation, ['save', 'saveQuietly'], true)) {
        expect($audit->$operation())->toBeTrue();
        $this->assertDatabaseCount('audit_logs', 1);

        return;
    }
    expect(function () use ($audit, $operation) {
        if (str_contains($operation, 'Each')) {
            $audit->$operation(['entity_id' => 1]);
        } elseif (in_array($operation, ['increment', 'decrement', 'incrementQuietly', 'decrementQuietly'], true)) {
            $audit->$operation('entity_id');
        } elseif (in_array($operation, ['delete', 'deleteQuietly'], true)) {
            $audit->$operation();
        } else {
            $audit->action = 'changed';
            $audit->$operation();
        }
    })->toThrow(LogicException::class, 'append-only');
    if ($persisted) {
        expect($audit->fresh()->entity_id)->toBe($original);
        expect($audit->fresh()->action)->toBe('test.status_changed');
    } else {
        $this->assertDatabaseCount('audit_logs', 0);
    }
})->with(['save', 'saveQuietly', 'delete', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly', 'incrementEach', 'decrementEach', 'incrementEachQuietly', 'decrementEachQuietly'])->with([true, false]);

test('restricts actor deletion and renumbering', function (string $operation) {
    $actor = User::factory()->create();
    $audit = AuditLog::factory()->byUser($actor)->create();
    try {
        $query = DB::table('users')->where('id', $actor->id);
        if ($operation === 'delete') {
            $query->delete();
        } else {
            $query->update(['id' => 99999999]);
        }
        $this->fail('MySQL discarded audit actor history.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1451);
    }
    $this->assertModelExists($audit);
})->with(['delete', 'update']);

test('refuses destructive rollback with audit history', function () {
    $audit = AuditLog::factory()->create();
    $migration = require database_path('migrations/2026_10_22_101156_create_audit_logs_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward fix');
    $this->assertModelExists($audit);
});

test('seeds synthetic redacted audit entries only', function () {
    $this->seed(AuditLogTestSeeder::class);
    expect(AuditLog::query()->orderBy('id')->pluck('actor_type')->all())->toBe([AuditActorType::System, AuditActorType::User, AuditActorType::Provider]);
    $this->assertDatabaseCount('audit_logs', 3);
});

test('refuses audit seeding outside isolated testing', function (string $boundary) {
    if ($boundary === 'environment') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config(['database.default' => 'mysql']);
    }
    try {
        expect(fn () => app(AuditLogTestSeeder::class)->run())->toThrow(LogicException::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config(['database.default' => 'mysql_testing']);
    }
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['environment', 'connection']);

test('matches the audit dictionary and query indexes', function () {
    $columns = collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, DATETIME_PRECISION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'audit_logs' ORDER BY ORDINAL_POSITION"));
    expect($columns->pluck('COLUMN_NAME')->all())->toBe(['id', 'actor_user_id', 'actor_type', 'entity_type', 'entity_id', 'action', 'before_redacted', 'after_redacted', 'reason', 'correlation_id', 'created_at']);
    expect($columns->where('IS_NULLABLE', 'YES')->pluck('COLUMN_NAME')->values()->all())->toBe(['actor_user_id', 'before_redacted', 'after_redacted', 'reason']);
    expect($columns->firstWhere('COLUMN_NAME', 'created_at')->DATETIME_PRECISION)->toBe(0);
    $indexes = collect(DB::select("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'audit_logs' GROUP BY INDEX_NAME"));
    expect($indexes->pluck('columns_list')->sort()->values()->all())->toBe(['actor_user_id,created_at,id', 'correlation_id,id', 'entity_type,entity_id,created_at,id', 'id']);
});
