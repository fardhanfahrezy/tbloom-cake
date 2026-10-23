<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Product images require isolated MySQL.');
    }
    $this->withoutVite();
});

test('reencodes approved images to randomized WebP and bounds dimensions without exposing original names', function (string $extension) {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $image = UploadedFile::fake()->image('original-secret.'.$extension, 2000, 1000);
    $this->actingAs($admin)->post('/staff/products/'.$product->id.'/images', ['image' => $image, 'alt_text' => 'Foto kue cokelat', 'sort_order' => 2, 'path' => '../unsafe'])->assertRedirect()->assertSessionHasNoErrors();
    $stored = ProductImage::query()->sole();
    expect($stored->path)->toMatch('/^products\/[a-f0-9-]{36}\.webp$/');
    expect($stored->path)->not->toContain('original-secret');
    $bytes = Storage::disk('public')->get($stored->path);
    $dimensions = getimagesizefromstring($bytes);
    expect([$dimensions[0], $dimensions[1], $dimensions['mime']])->toBe([1600, 800, 'image/webp']);
    expect($stored->alt_text)->toBe('Foto kue cokelat');
    expect($stored->sort_order)->toBe(2);
    $this->get('/catalog/'.$product->slug)->assertOk()->assertSee('Foto kue cokelat');
    $this->assertDatabaseCount('audit_logs', 1);
})->with(['png', 'jpg', 'webp']);

test('rejects spoofed SVG GIF oversized and excessive dimension files without storing objects', function (string $case) {
    Storage::fake('public');
    $product = Product::factory()->create();
    $image = match ($case) {
        'spoof' => UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "unsafe";'),
        'svg' => UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'gif' => UploadedFile::fake()->image('animated.gif'),
        'oversize' => UploadedFile::fake()->image('image.jpg')->size(5121),
        'dimension' => UploadedFile::fake()->image('wide.png', 4097, 1),
        'corrupt' => UploadedFile::fake()->createWithContent('broken.png', substr(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6lzAAAAAASUVORK5CYII='), 0, 40)),
    };
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/products/'.$product->id.'/images', ['image' => $image, 'alt_text' => 'Foto uji', 'sort_order' => 0])->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('product_images', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['spoof', 'svg', 'gif', 'oversize', 'dimension', 'corrupt']);

test('strips trailing executable content by decoding and reencoding image bytes', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    $png = UploadedFile::fake()->image('clean.png');
    $image = UploadedFile::fake()->createWithContent('polyglot.php.jpg', file_get_contents($png->getRealPath()).'<?php echo "unsafe marker";');
    $this->actingAs(User::factory()->admin()->create())->post('/staff/products/'.$product->id.'/images', ['image' => $image, 'alt_text' => 'Foto aman', 'sort_order' => 0])->assertRedirect()->assertSessionHasNoErrors();
    $stored = ProductImage::query()->sole();
    expect(Storage::disk('public')->get($stored->path))->not->toContain('<?php', 'unsafe marker');
});

test('validates alternative text and sort order before writes', function (array $invalid, string $field) {
    Storage::fake('public');
    $product = Product::factory()->create();
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/products/'.$product->id.'/images', array_replace(['image' => UploadedFile::fake()->image('test.png'), 'alt_text' => 'Foto kue', 'sort_order' => 0], $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    $this->assertDatabaseCount('product_images', 0);
})->with([[['alt_text' => ''], 'alt_text'], [['alt_text' => str_repeat('x', 256)], 'alt_text'], [['sort_order' => -1], 'sort_order']]);

test('denies customer uploads and cross-product image edits before writing or deleting files', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    $foreign = ProductImage::factory()->create();
    Storage::disk('public')->put($foreign->path, 'synthetic existing file');
    $this->actingAs(User::factory()->create())->postJson('/staff/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('test.png'), 'alt_text' => 'Foto kue', 'sort_order' => 0])->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->patchJson('/staff/products/'.$product->id.'/images/'.$foreign->id, ['alt_text' => 'Tidak sah', 'sort_order' => 0])->assertNotFound();
    $this->delete('/staff/products/'.$product->id.'/images/'.$foreign->id)->assertNotFound();
    Storage::disk('public')->assertExists($foreign->path);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('replaces images at a new key edits metadata and deletes only the owned public image', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $stored = ProductImage::factory()->create();
    $product = $stored->product;
    $oldPath = $stored->path;
    Storage::disk('public')->put($oldPath, 'old image');
    $this->actingAs($admin)->get('/staff/products/'.$product->id.'/images/'.$stored->id.'/edit')->assertOk();
    $this->patch('/staff/products/'.$product->id.'/images/'.$stored->id, ['image' => UploadedFile::fake()->image('replace.jpg'), 'alt_text' => 'Baru', 'sort_order' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $newPath = $stored->fresh()->path;
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    $this->patch('/staff/products/'.$product->id.'/images/'.$stored->id, ['alt_text' => '<script>alert(1)</script>', 'sort_order' => 3])->assertRedirect()->assertSessionHasNoErrors();
    expect($stored->fresh()->path)->toBe($newPath);
    $this->get('/catalog/'.$product->slug)->assertOk()->assertSee($stored->fresh()->alt_text)->assertDontSee($stored->fresh()->alt_text, false);
    $this->delete('/staff/products/'.$product->id.'/images/'.$stored->id)->assertRedirect();
    Storage::disk('public')->assertMissing($newPath);
    $this->assertDatabaseCount('product_images', 0);
    $this->assertDatabaseCount('audit_logs', 3);
});

test('cleans the new public object and rolls back metadata when audit persistence fails', function () {
    Storage::fake('public');
    $product = Product::factory()->create();
    $admin = User::factory()->admin()->create();
    Exceptions::fake();
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit failure.'));
    try {
        $this->actingAs($admin)->post('/staff/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('test.png'), 'alt_text' => 'Foto', 'sort_order' => 0])->assertServerError();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        expect(Storage::disk('public')->allFiles())->toBe([]);
        Exceptions::assertReported(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
});

test('refuses metadata when writing or reading back the new image fails', function (string $failure) {
    $product = Product::factory()->create();
    $admin = User::factory()->admin()->create();
    Exceptions::fake();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->andReturn($failure === 'readback');
    if ($failure === 'readback') {
        $disk->shouldReceive('get')->once()->andReturn('synthetic tampered bytes');
    }
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    $this->actingAs($admin)->post('/staff/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('test.png'), 'alt_text' => 'Foto', 'sort_order' => 0])->assertServerError();
    $this->assertDatabaseCount('product_images', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Exceptions::assertReported(RuntimeException::class);
})->with(['write', 'readback']);

test('keeps the old image and metadata if replacement audit fails', function () {
    Storage::fake('public');
    $stored = ProductImage::factory()->create();
    $oldPath = $stored->path;
    Storage::disk('public')->put($oldPath, 'old image');
    $admin = User::factory()->admin()->create();
    Exceptions::fake();
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic replacement failure.'));
    try {
        $this->actingAs($admin)->patch('/staff/products/'.$stored->product_id.'/images/'.$stored->id, ['image' => UploadedFile::fake()->image('new.png'), 'alt_text' => 'New photo', 'sort_order' => 0])->assertServerError();
        expect($stored->fresh()->path)->toBe($oldPath);
        expect(Storage::disk('public')->allFiles())->toBe([$oldPath]);
        $this->assertDatabaseCount('audit_logs', 0);
        Exceptions::assertReported(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
});

test('does not delete private files through malformed legacy public metadata', function () {
    Storage::fake('public');
    Storage::fake('private_uploads');
    Storage::disk('private_uploads')->put('proof.png', 'private proof');
    $image = ProductImage::factory()->create(['path' => '../private_uploads/proof.png']);
    $this->actingAs(User::factory()->admin()->create())->delete('/staff/products/'.$image->product_id.'/images/'.$image->id)->assertRedirect();
    Storage::disk('private_uploads')->assertExists('proof.png');
    $this->assertModelMissing($image);
});
