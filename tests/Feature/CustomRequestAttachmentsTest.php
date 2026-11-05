<?php

use App\CustomRequestAttachments;
use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\User;
use App\PrivateUploadStorage;
use App\SubmitCustomRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\CommittedMySqlDatabase;

uses(CommittedMySqlDatabase::class);

beforeEach(function () {
    config(['notifications.recipient_hmac_key' => 'base64:'.base64_encode(str_repeat('c', 32))]);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC'));
});

function customAttachmentFixture(User $customer, int $count = 1): CustomCakeRequest
{
    $uploads = [];
    for ($i = 0; $i < $count; $i++) {
        $uploads[] = UploadedFile::fake()->image('private-personal-name-'.$i.'.png', 2, 2);
    }

    return app(SubmitCustomRequest::class)->create($customer, ['pickup_date' => '2026-11-08', 'size' => '20 cm', 'flavor_code' => 'chocolate',
        'filling_code' => 'strawberry', 'color' => 'Putih', 'inscription_choice' => 'none', 'edible_print' => false,
        'fondant' => false, 'idempotency_key' => 'attachment-test', 'attachments' => $uploads]);
}

test('only the customer owner or active staff can download private custom attachments', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $custom = customAttachmentFixture($customer);
    $attachment = $custom->attachments()->sole();
    $url = '/custom-attachments/'.$attachment->id;
    $this->get($url)->assertRedirectToRoute('login');
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    $this->actingAs($customer)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="custom-reference-'.$attachment->id.'.png"')
        ->assertContent(Storage::disk('private_uploads')->get($attachment->private_path));
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    $this->actingAs(User::factory()->owner()->create())->get($url)->assertOk();
});

test('three reference images remain private encrypted and accessible through the request detail', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $custom = customAttachmentFixture($customer, 3);
    expect($custom->attachments()->count())->toBe(3);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(3);
    expect(DB::table('custom_cake_request_attachments')->value('original_name_ciphertext'))->not->toContain('private-personal-name');
    $this->actingAs($customer)->get('/custom-requests/'.$custom->id)->assertOk()->assertSee('Unduh referensi 3')
        ->assertDontSee('private-personal-name')->assertDontSee($custom->attachments()->first()->private_path);
    $this->actingAs(User::factory()->create())->get('/custom-requests/'.$custom->id)->assertNotFound();
    expect($custom->attachments()->first()->toArray())->not->toHaveKeys(['private_path', 'original_name_ciphertext']);
});

test('upload count and file content validation reject missing extra fake and active files', function (string $kind) {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $files = match ($kind) {
        'zero' => [],
        'four' => array_map(fn () => UploadedFile::fake()->image('ref.png', 2, 2), range(1, 4)),
        'svg' => [UploadedFile::fake()->createWithContent('ref.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'php' => [UploadedFile::fake()->createWithContent('ref.jpg', '<?php echo "executable";')],
        'pdf' => [UploadedFile::fake()->createWithContent('ref.png', '%PDF-1.7 untrusted')],
        'truncated' => [UploadedFile::fake()->createWithContent('ref.jpg', "\xff\xd8\xff\xe0broken")],
    };
    $this->actingAs($customer)->postJson('/custom-requests', [
        'pickup_date' => '2026-11-08', 'size' => '20 cm', 'flavor_code' => 'chocolate', 'filling_code' => 'strawberry',
        'color' => 'Putih', 'inscription_choice' => 'none', 'edible_print' => false, 'fondant' => false,
        'idempotency_key' => 'invalid-files', 'attachments' => $files,
    ])->assertUnprocessable();
    $this->assertDatabaseCount('custom_cake_requests', 0);
    expect(Storage::disk('private_uploads')->allFiles())->toBeEmpty();
})->with(['zero', 'four', 'svg', 'php', 'pdf', 'truncated']);

test('five megabyte upload limit is enforced on real bytes', function (int $size, bool $allowed) {
    Storage::fake('private_uploads');
    $image = UploadedFile::fake()->image('ref.png', 2, 2);
    $bytes = file_get_contents($image->getRealPath());
    $upload = UploadedFile::fake()->createWithContent('ref.png', $bytes.str_repeat("\0", $size - strlen($bytes)));
    $response = $this->actingAs(User::factory()->create())->postJson('/custom-requests', [
        'pickup_date' => '2026-11-08', 'size' => '20 cm', 'flavor_code' => 'chocolate', 'filling_code' => 'strawberry',
        'color' => 'Putih', 'inscription_choice' => 'none', 'edible_print' => false, 'fondant' => false,
        'idempotency_key' => 'size-test', 'attachments' => [$upload],
    ]);
    if ($allowed) {
        $response->assertCreated();
        expect(CustomCakeRequestAttachment::query()->sole()->size_bytes)->toBe(5242880);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->assertDatabaseCount('custom_cake_requests', 0);
    }
})->with([[5242880, true], [5242881, false]]);

test('missing or corrupted private bytes are never downloaded', function (bool $missing) {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $attachment = customAttachmentFixture($customer)->attachments()->sole();
    if ($missing) {
        Storage::disk('private_uploads')->delete($attachment->private_path);
    } else {
        Storage::disk('private_uploads')->put($attachment->private_path, 'modified');
    }
    expect(fn () => app(CustomRequestAttachments::class)->download($attachment->id, $customer))->toThrow(RuntimeException::class);
})->with([false, true]);

test('download authorization is rechecked after file I O without holding row locks', function (bool $metadataChange) {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $attachment = customAttachmentFixture($customer)->attachments()->sole();
    $storage = app(PrivateUploadStorage::class);
    $spy = Mockery::mock(PrivateUploadStorage::class);
    $spy->shouldReceive('assertReadable')->once()->andReturnUsing(function ($metadata) use ($storage, $customer, $metadataChange, $attachment): void {
        expect(DB::transactionLevel())->toBe(0);
        $storage->assertReadable($metadata);
        if ($metadataChange) {
            DB::table('custom_cake_request_attachments')->where('id', $attachment->id)->update(['original_name_ciphertext' => encrypt('changed.png', false)]);
        } else {
            User::query()->whereKey($customer->id)->update(['is_active' => false]);
        }
    });
    app()->instance(PrivateUploadStorage::class, $spy);
    $this->actingAs($customer)->get('/custom-attachments/'.$attachment->id)->assertStatus($metadataChange ? 409 : 404);
})->with([false, true]);

test('a failure staging the second file cannot commit partial metadata', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $storage = app(PrivateUploadStorage::class);
    $spy = Mockery::mock(PrivateUploadStorage::class);
    $spy->shouldReceive('store')->once()->ordered()->andReturnUsing(fn ($upload, $purpose) => $storage->store($upload, $purpose));
    $spy->shouldReceive('store')->once()->ordered()->andThrow(new RuntimeException('Synthetic storage failure'));
    app()->instance(PrivateUploadStorage::class, $spy);
    expect(fn () => customAttachmentFixture($customer, 2))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('custom_cake_requests', 0);
    $this->assertDatabaseCount('custom_cake_request_attachments', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    expect(Storage::disk('private_uploads')->allFiles())->toHaveCount(1);
});

test('private attachment policy denies inactive actors and permits only active owner or staff', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $attachment = customAttachmentFixture($customer)->attachments()->sole();
    expect(Gate::forUser($customer)->allows('view', $attachment))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->inspect('view', $attachment)->status())->toBe(404);
    foreach ([User::factory()->admin()->inactive()->create(), User::factory()->owner()->inactive()->create()] as $actor) {
        expect(Gate::forUser($actor)->inspect('view', $attachment)->status())->toBe(404);
    }
});

test('JPEG and WebP uploads are accepted using verified content types', function (string $extension, string $mime) {
    Storage::fake('private_uploads');
    $this->actingAs(User::factory()->create())->postJson('/custom-requests', [
        'pickup_date' => '2026-11-08', 'size' => '20 cm', 'flavor_code' => 'chocolate', 'filling_code' => 'strawberry',
        'color' => 'Putih', 'inscription_choice' => 'none', 'edible_print' => false, 'fondant' => false,
        'idempotency_key' => 'type-test', 'attachments' => [UploadedFile::fake()->image('ref.'.$extension, 2, 2)],
    ])->assertCreated();
    expect(CustomCakeRequestAttachment::query()->sole()->mime_type)->toBe($mime);
})->with([['jpg', 'image/jpeg'], ['webp', 'image/webp']]);

test('downloads reject wrong storage namespaces and nested caller transactions', function () {
    Storage::fake('private_uploads');
    $customer = User::factory()->create();
    $attachment = customAttachmentFixture($customer)->attachments()->sole();
    DB::beginTransaction();
    try {
        expect(fn () => app(CustomRequestAttachments::class)->download($attachment->id, $customer))->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }
    DB::table('custom_cake_request_attachments')->where('id', $attachment->id)->update(['private_path' => 'refund-evidence/'.basename($attachment->private_path)]);
    $this->actingAs($customer)->get('/custom-attachments/'.$attachment->id)->assertConflict();
});
