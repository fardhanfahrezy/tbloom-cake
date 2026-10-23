<?php

use App\Models;
use App\NotificationChannel;
use App\NotificationRecipientIdentity;
use App\PrivateUploadPurpose;
use App\PrivateUploadStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Recovery rehearsal only allows isolated MySQL.');
    }
    $root = storage_path('framework/testing/schema-recovery-'.Str::uuid());
    config(['filesystems.schema_recovery_root' => $root, 'filesystems.disks.private_uploads.root' => $root.'/live']);
    Storage::forgetDisk('private_uploads');
});

afterEach(function () {
    Model::encryptUsing(null);
    try {
        if (config('database.default') === 'mysql_testing') {
            $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        }
    } finally {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
        $root = config('filesystems.schema_recovery_root');
        if (is_string($root) && str_starts_with($root, storage_path('framework/testing/'))
            && preg_match('/\Aschema-recovery-[a-f0-9-]{36}\z/', basename($root))) {
            Storage::forgetDisk('private_uploads');
            File::deleteDirectory($root);
        }
    }
});

test('restores every synthetic table with private bytes previous encryption keys and HMAC history', function () {
    Http::preventStrayRequests();
    Mail::fake();
    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    $oldEncryptionKey = str_repeat('e', 32);
    $newEncryptionKey = str_repeat('r', 32);
    $oldHmacKey = 'base64:'.base64_encode(str_repeat('h', 32));
    config(['notifications.recipient_hmac_key' => $oldHmacKey, 'notifications.previous_recipient_hmac_keys' => []]);
    Model::encryptUsing(new Encrypter($oldEncryptionKey, 'AES-256-CBC'));

    $owner = Models\User::factory()->owner()->create();
    $admin = Models\User::factory()->admin()->create();
    $customer = Models\User::factory()->create();
    $product = Models\Product::factory()->create();
    $variant = Models\ProductVariant::factory()->for($product)->create(['price' => 100000]);
    $image = Models\ProductImage::factory()->for($product)->create();
    $order = Models\Order::factory()->for($customer, 'user')->paid()->create();
    $availability = Models\DailyAvailability::factory()->for($product)->create(['availability_date' => $order->pickup_date->toDateString(), 'updated_by' => $admin->id]);
    $item = Models\OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'daily_availability_id' => $availability->id]);
    $payment = Models\Payment::factory()->for($order)->paid()->create(['snap_token' => '[TEST] synthetic recovery token']);
    $event = Models\PaymentEvent::factory()->for($payment)->processed()->create();
    $request = Models\OrderRequest::factory()->for($order)->applied()->create(['reviewed_by' => $admin->id]);
    $refund = Models\RefundLog::factory()->processing()->create(['payment_id' => $payment->id, 'assigned_admin_id' => $admin->id, 'bank_verified_by' => $admin->id]);
    $transfer = Models\RefundTransfer::factory()->unknown()->create(['refund_log_id' => $refund->id]);
    $upload = app(PrivateUploadStorage::class);
    $proof = $upload->store(UploadedFile::fake()->image('recovered-proof.png'), PrivateUploadPurpose::RefundEvidence);
    $evidence = Models\RefundEvidence::factory()->create(array_replace(Arr::except($proof, ['original_name']), ['refund_transfer_id' => $transfer->id, 'original_name_ciphertext' => $proof['original_name']]));
    $movement = Models\PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id]);
    $correction = Models\PaymentMovementCorrection::factory()->for($movement, 'movement')->create(['approved_by' => $owner->id]);
    $custom = Models\CustomCakeRequest::factory()->for($customer, 'user')->create();
    $reference = $upload->store(UploadedFile::fake()->image('recovered-reference.png'), PrivateUploadPurpose::CustomReference);
    $attachment = Models\CustomCakeRequestAttachment::factory()->create(array_replace(Arr::except($reference, ['original_name']), ['custom_cake_request_id' => $custom->id, 'original_name_ciphertext' => $reference['original_name']]));
    $audit = Models\AuditLog::factory()->byUser($owner)->create(['entity_type' => 'order', 'entity_id' => $order->id]);
    $delivery = Models\NotificationDelivery::factory()->create(['entity_type' => 'order', 'entity_id' => $order->id, 'event_key' => 'test:order:'.$order->id.':paid:v1']);
    Models\Testimonial::factory()->create();
    $closed = Models\Payment::factory()->closedUnpaid()->create();

    DB::table('password_reset_tokens')->insert(['email' => $customer->email, 'token' => hash('sha256', '[TEST] not an actual reset'), 'created_at' => '2026-10-22 00:00:00']);
    DB::table('sessions')->insert(['id' => 'TEST-recovery-session', 'user_id' => $customer->id, 'payload' => base64_encode(serialize([])), 'last_activity' => 1792627200]);
    DB::table('cache')->insert(['key' => 'TEST-recovery', 'value' => serialize('synthetic'), 'expiration' => 1792627200]);
    DB::table('cache_locks')->insert(['key' => 'TEST-recovery', 'owner' => 'TEST-owner', 'expiration' => 1792627200]);
    DB::table('jobs')->insert(['queue' => 'TEST-disabled', 'payload' => '{"fixture":"not-executable"}', 'attempts' => 0, 'available_at' => 1792627200, 'created_at' => 1792627200]);
    DB::table('job_batches')->insert(['id' => 'TEST-batch', 'name' => 'TEST-disabled', 'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => 1792627200]);
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'TEST-disabled', 'queue' => 'TEST-disabled', 'payload' => '{"fixture":"not-executable"}', 'exception' => '[TEST] synthetic failure']);

    $tables = ['migrations', 'users', 'products', 'product_variants', 'product_images', 'daily_availabilities', 'orders', 'order_items', 'payments', 'payment_events', 'order_requests', 'refund_logs', 'refund_transfers', 'refund_evidence', 'payment_movements', 'payment_movement_corrections', 'custom_cake_requests', 'custom_cake_request_attachments', 'audit_logs', 'notification_deliveries', 'testimonials', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];
    $primaryKeys = collect(DB::select("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_KEY = 'PRI'"))->pluck('COLUMN_NAME', 'TABLE_NAME');
    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->orderBy($primaryKeys[$table])->get()->map(fn (object $row): array => (array) $row)->all();
        expect($snapshot[$table])->not->toBeEmpty();
    }
    $backup = Storage::build(['driver' => 'local', 'root' => config('filesystems.schema_recovery_root').'/backup', 'throw' => true]);
    $backup->put('database.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
    foreach ([$proof, $reference] as $object) {
        $backup->put('objects/'.$object['private_path'], Storage::disk('private_uploads')->get($object['private_path']));
    }
    $expectedBank = $refund->account_number;
    $expectedToken = $payment->snap_token;
    $expectedRecipient = $delivery->recipient_ciphertext;
    $expectedClosure = $closed->getRawOriginal('closure_event_id');

    $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    config(['filesystems.disks.private_uploads.root' => config('filesystems.schema_recovery_root').'/restored']);
    Storage::forgetDisk('private_uploads');
    $recovered = json_decode($backup->get('database.json'), true, flags: JSON_THROW_ON_ERROR);
    $generated = collect(DB::select("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND GENERATION_EXPRESSION <> ''"))->groupBy('TABLE_NAME');
    DB::transaction(function () use ($tables, $recovered, $generated): void {
        DB::table('migrations')->delete();
        foreach ($tables as $table) {
            foreach ($recovered[$table] as $row) {
                $row = Arr::except($row, $generated->get($table, collect())->pluck('COLUMN_NAME')->all());
                if ($table === 'payments') {
                    $row['closure_result'] = null;
                    $row['closure_event_id'] = null;
                    $row['closure_verified_at'] = null;
                }
                DB::table($table)->insert($row);
            }
        }
        foreach ($recovered['payments'] as $row) {
            if ($row['closure_event_id'] !== null) {
                DB::table('payments')->where('id', $row['id'])->update(Arr::only($row, ['closure_result', 'closure_event_id', 'closure_verified_at']));
            }
        }
    });
    foreach ([$proof, $reference] as $object) {
        Storage::disk('private_uploads')->put($object['private_path'], $backup->get('objects/'.$object['private_path']));
        $upload->assertReadable($object);
    }
    foreach ($tables as $table) {
        $actual = DB::table($table)->orderBy($primaryKeys[$table])->get()->map(fn (object $row): array => (array) $row)->all();
        expect($actual)->toBe($snapshot[$table]);
    }
    Model::encryptUsing(new Encrypter($newEncryptionKey, 'AES-256-CBC'));
    expect(fn () => $evidence->fresh()->original_name_ciphertext)->toThrow(DecryptException::class);
    expect(fn () => $delivery->fresh()->recipient_ciphertext)->toThrow(DecryptException::class);
    Model::encryptUsing((new Encrypter($newEncryptionKey, 'AES-256-CBC'))->previousKeys([$oldEncryptionKey]));
    expect($evidence->fresh()->original_name_ciphertext)->toBe('recovered-proof.png');
    expect($attachment->fresh()->original_name_ciphertext)->toBe('recovered-reference.png');
    expect($refund->fresh()->account_number)->toBe($expectedBank);
    expect($transfer->fresh()->account_number)->toBe($expectedBank);
    expect($payment->fresh()->snap_token)->toBe($expectedToken);
    expect($delivery->fresh()->recipient_ciphertext)->toBe($expectedRecipient);
    expect($closed->fresh()->closureEvent->id)->toBe($expectedClosure);
    expect($correction->fresh()->movement->payment->order->is($order))->toBeTrue();
    expect($movement->fresh()->effectiveFact()['amount'])->toBe(90000);
    expect($item->fresh()->dailyAvailability->product->is($product))->toBeTrue();
    expect($product->fresh()->images->sole()->is($image))->toBeTrue();
    expect($request->fresh()->user->is($customer))->toBeTrue();
    expect($custom->fresh()->attachments->sole()->is($attachment))->toBeTrue();
    expect($audit->fresh()->actor->is($owner))->toBeTrue();

    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
    $query = Models\NotificationDelivery::query()->where('event_key', $delivery->event_key)->where('template_key', $delivery->template_key);
    $identity = app(NotificationRecipientIdentity::class);
    expect((clone $query)->whereIn('recipient_key', $identity->candidateKeys(NotificationChannel::Email, $expectedRecipient['address']))->exists())->toBeFalse();
    config(['notifications.previous_recipient_hmac_keys' => [$oldHmacKey]]);
    expect((clone $query)->whereIn('recipient_key', $identity->candidateKeys(NotificationChannel::Email, $expectedRecipient['address']))->sole()->is($delivery))->toBeTrue();
    Mail::assertNothingSent();
});
