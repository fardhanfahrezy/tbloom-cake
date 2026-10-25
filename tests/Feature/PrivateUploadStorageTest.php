<?php

use App\PrivateUploadPurpose;
use App\PrivateUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function privateUploadPngBytes(): string
{
    $image = UploadedFile::fake()->image('reference.png', 2, 2);

    return file_get_contents($image->getRealPath());
}

test('stores verified private bytes using a generated object key and exact metadata', function () {
    Storage::fake('private_uploads');
    $bytes = privateUploadPngBytes();
    $upload = UploadedFile::fake()->createWithContent('../../customer-name.png', $bytes);
    $stored = app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::CustomReference);

    expect($stored['private_path'])->toMatch('~^custom-requests/[a-f0-9-]{36}$~');
    expect($stored['mime_type'])->toBe('image/png');
    expect($stored['size_bytes'])->toBe(strlen($bytes));
    expect($stored['sha256'])->toBe(hash('sha256', $bytes));
    expect($stored['original_name'])->toBe('customer-name.png');
    expect(Storage::disk('private_uploads')->get($stored['private_path']))->toBe($bytes);
    Storage::disk('private_uploads')->assertExists($stored['private_path']);
    app(PrivateUploadStorage::class)->assertReadable($stored);
});

test('accepts only the approved content types for each private purpose', function (string $purpose, string $extension, string $mime) {
    Storage::fake('private_uploads');
    $upload = $extension === 'pdf'
        ? UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")
        : UploadedFile::fake()->image('reference.'.$extension, 2, 2);
    $stored = app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::from($purpose));

    expect($stored['mime_type'])->toBe($mime);
    expect($stored['private_path'])->toStartWith($purpose.'/');
    Storage::disk('private_uploads')->assertExists($stored['private_path']);
})->with([
    ['custom-requests', 'jpeg', 'image/jpeg'],
    ['custom-requests', 'png', 'image/png'],
    ['custom-requests', 'webp', 'image/webp'],
    ['refund-evidence', 'jpeg', 'image/jpeg'],
    ['refund-evidence', 'png', 'image/png'],
    ['refund-evidence', 'webp', 'image/webp'],
    ['refund-evidence', 'pdf', 'application/pdf'],
]);

test('rejects invalid or spoofed private content before storage', function (string $name, string $bytes, string $purpose) {
    Storage::fake('private_uploads');
    $upload = UploadedFile::fake()->createWithContent($name, $bytes);

    try {
        app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::from($purpose));
        $this->fail('Invalid private upload was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('upload');
    }
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with([
    'text posing as PNG' => ['photo.png', 'plain text is not a PNG', 'custom-requests'],
    'SVG' => ['photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'custom-requests'],
    'executable' => ['photo.png', '<?php echo "executable";', 'refund-evidence'],
    'empty' => ['photo.png', '', 'custom-requests'],
    'PDF custom reference' => ['photo.png', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n", 'custom-requests'],
]);

test('accepts exactly five MiB of actual bytes and rejects one byte more', function () {
    Storage::fake('private_uploads');
    $png = privateUploadPngBytes();
    $bytes = $png.str_repeat("\0", 5242880 - strlen($png));
    $valid = UploadedFile::fake()->createWithContent('reference.png', $bytes);
    $stored = app(PrivateUploadStorage::class)->store($valid, PrivateUploadPurpose::CustomReference);
    expect($stored['size_bytes'])->toBe(5242880);
    Storage::disk('private_uploads')->assertExists($stored['private_path']);

    $invalid = UploadedFile::fake()->createWithContent('reference.png', $bytes."\0");
    expect(fn () => app(PrivateUploadStorage::class)->store($invalid, PrivateUploadPurpose::CustomReference))->toThrow(ValidationException::class);
    expect(Storage::disk('private_uploads')->allFiles())->toBe([$stored['private_path']]);
});

test('rejects truncated image content before writing any private object', function (string $extension, PrivateUploadPurpose $purpose) {
    Storage::fake('private_uploads');
    $valid = UploadedFile::fake()->image('reference.'.$extension, 2, 2);
    $bytes = file_get_contents($valid->getRealPath());
    $length = match ($extension) {
        'png' => 33,
        'jpeg' => strpos($bytes, "\xff\xda") + 10,
        'webp' => 30,
    };
    $upload = UploadedFile::fake()->createWithContent('reference.'.$extension, substr($bytes, 0, $length));

    expect(fn () => app(PrivateUploadStorage::class)->store($upload, $purpose))->toThrow(ValidationException::class);

    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with(['png', 'jpeg', 'webp'])->with([PrivateUploadPurpose::CustomReference, PrivateUploadPurpose::RefundEvidence]);

test('rejects image dimensions beyond the decoder resource limits', function (int $width, int $height) {
    Storage::fake('private_uploads');
    $upload = UploadedFile::fake()->image('reference.png', $width, $height);

    expect(fn () => app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::CustomReference))->toThrow(ValidationException::class);

    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with([[4097, 1], [1, 4097], [4000, 3001]]);

test('never reuses original names as object keys', function () {
    Storage::fake('private_uploads');
    $upload = UploadedFile::fake()->image('same-name.png', 2, 2);
    $first = app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::CustomReference);
    $second = app(PrivateUploadStorage::class)->store($upload, PrivateUploadPurpose::CustomReference);
    expect($first['private_path'])->not->toBe($second['private_path']);
    Storage::disk('private_uploads')->assertExists([$first['private_path'], $second['private_path']]);
});

test('detects missing or modified objects before trusting their metadata', function (string $change) {
    Storage::fake('private_uploads');
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('reference.png'), PrivateUploadPurpose::RefundEvidence);
    if ($change === 'missing') {
        Storage::disk('private_uploads')->delete($stored['private_path']);
    } elseif ($change === 'bytes') {
        Storage::disk('private_uploads')->put($stored['private_path'], 'changed bytes');
    } else {
        $stored['sha256'] = str_repeat('0', 64);
    }
    expect(fn () => app(PrivateUploadStorage::class)->assertReadable($stored))->toThrow(RuntimeException::class);
})->with(['missing', 'bytes', 'digest']);

test('rejects path traversal or unrelated namespaces in persisted metadata', function (string $path) {
    Storage::fake('private_uploads');
    $stored = ['private_path' => $path, 'sha256' => str_repeat('a', 64), 'size_bytes' => 1];
    expect(fn () => app(PrivateUploadStorage::class)->assertReadable($stored))->toThrow(InvalidArgumentException::class);
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with(['../.env', 'custom-requests/../../.env', '/etc/passwd', 'public/reference.png', 'refund-evidence/not-a-uuid']);

test('configures the private disk without public serving and fails closed on writes', function () {
    $disk = config('filesystems.disks.private_uploads');
    expect($disk['driver'])->toBe('local');
    expect($disk['serve'])->toBeFalse();
    expect($disk['throw'])->toBeTrue();
    expect($disk['visibility'])->toBe('private');
    expect($disk['root'])->not->toBe(config('filesystems.disks.public.root'))->not->toBe(config('filesystems.disks.local.root'));
    expect(config('filesystems.links'))->not->toContain($disk['root']);
    expect(Storage::disk('private_uploads')->providesTemporaryUrls())->toBeFalse();
});

test('rejects invalid byte counts and digests in persisted metadata', function (array $overrides) {
    Storage::fake('private_uploads');
    $stored = array_replace([
        'private_path' => 'custom-requests/123e4567-e89b-12d3-a456-426614174000',
        'size_bytes' => 1,
        'sha256' => str_repeat('a', 64),
    ], $overrides);
    expect(fn () => app(PrivateUploadStorage::class)->assertReadable($stored))->toThrow(InvalidArgumentException::class);
    Storage::disk('private_uploads')->assertDirectoryEmpty('');
})->with([
    [['size_bytes' => 0]],
    [['size_bytes' => -1]],
    [['size_bytes' => 5242881]],
    [['sha256' => '']],
    [['sha256' => str_repeat('A', 64)]],
    [['sha256' => str_repeat('g', 64)]],
]);

test('refuses an object key collision without overwriting existing private bytes', function () {
    Storage::fake('private_uploads');
    $uuid = Str::uuid();
    Str::createUuidsUsing(fn () => $uuid);
    try {
        $first = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('first.png', 2, 2), PrivateUploadPurpose::CustomReference);
        $original = Storage::disk('private_uploads')->get($first['private_path']);
        expect(fn () => app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('other.png', 3, 3), PrivateUploadPurpose::CustomReference))->toThrow(RuntimeException::class);
        expect(Storage::disk('private_uploads')->get($first['private_path']))->toBe($original);
        Storage::disk('private_uploads')->assertExists($first['private_path']);
    } finally {
        Str::createUuidsNormally();
    }
});
