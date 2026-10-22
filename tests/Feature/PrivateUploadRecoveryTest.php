<?php

use App\Models\RefundEvidence;
use App\Models\RefundTransferStatus;
use App\PrivateUploadPurpose;
use App\PrivateUploadStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCreateDirectory;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Real private metadata recovery requires phpunit.mysql.xml.');
    }
    $root = storage_path('framework/testing/private-recovery-'.Str::uuid());
    config([
        'filesystems.private_upload_test_root' => $root,
        'filesystems.disks.private_uploads.root' => $root.'/live',
    ]);
    Storage::forgetDisk('private_uploads');
});

afterEach(function () {
    Model::encryptUsing(null);
    $root = config('filesystems.private_upload_test_root');
    if (is_string($root) && str_starts_with($root, storage_path('framework/testing/'))
        && preg_match('/\Aprivate-recovery-[a-f0-9-]{36}\z/', basename($root))) {
        Storage::forgetDisk('private_uploads');
        File::deleteDirectory($root);
    }
});

test('reads real private bytes through a newly constructed disk without a public route', function () {
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('private-reference.png'), PrivateUploadPurpose::CustomReference);
    $reopened = Storage::build(config('filesystems.disks.private_uploads'));
    expect(hash('sha256', $reopened->get($stored['private_path'])))->toBe($stored['sha256']);
    expect($reopened->size($stored['private_path']))->toBe($stored['size_bytes']);
    expect(is_file($reopened->path($stored['private_path'])))->toBeTrue();
    $unrelatedDiskUrl = Storage::disk('local')->temporaryUrl($stored['private_path'], now()->addMinute());
    $this->get($unrelatedDiskUrl)->assertNotFound();
    $this->get('/storage/'.$stored['private_path'])->assertForbidden();
});

test('restores encrypted metadata and real private objects with independently recovered keys', function () {
    $oldKey = str_repeat('a', 32);
    $newKey = str_repeat('b', 32);
    Model::encryptUsing(new Encrypter($oldKey, 'AES-256-CBC'));
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('private-proof.png'), PrivateUploadPurpose::RefundEvidence);
    $evidence = RefundEvidence::factory()->create([
        'private_path' => $stored['private_path'],
        'original_name_ciphertext' => $stored['original_name'],
        'mime_type' => $stored['mime_type'],
        'size_bytes' => $stored['size_bytes'],
        'sha256' => $stored['sha256'],
    ]);
    $snapshot = $evidence->getAttributes();
    expect($snapshot['original_name_ciphertext'])->not->toContain('private-proof.png');
    $backup = Storage::build(['driver' => 'local', 'root' => config('filesystems.private_upload_test_root').'/backup', 'throw' => true]);
    $backup->put('objects/'.$stored['private_path'], Storage::disk('private_uploads')->get($stored['private_path']));
    $backup->put('metadata.json', json_encode($snapshot, JSON_THROW_ON_ERROR));

    DB::table('refund_evidence')->where('id', $evidence->id)->delete();
    Storage::disk('private_uploads')->delete($stored['private_path']);
    config(['filesystems.disks.private_uploads.root' => config('filesystems.private_upload_test_root').'/restored']);
    Storage::forgetDisk('private_uploads');
    Storage::disk('private_uploads')->put($stored['private_path'], $backup->get('objects/'.$stored['private_path']));
    DB::table('refund_evidence')->insert(json_decode($backup->get('metadata.json'), true, flags: JSON_THROW_ON_ERROR));

    Model::encryptUsing(new Encrypter($newKey, 'AES-256-CBC'));
    expect(fn () => $evidence->fresh()->original_name_ciphertext)->toThrow(DecryptException::class);
    $recovered = (new Encrypter($newKey, 'AES-256-CBC'))->previousKeys([$oldKey]);
    Model::encryptUsing($recovered);
    expect($evidence->fresh()->original_name_ciphertext)->toBe('private-proof.png');
    app(PrivateUploadStorage::class)->assertReadable($stored);
    expect($evidence->fresh()->sha256)->toBe($stored['sha256']);
    expect(DB::table('refund_evidence')->where('id', $evidence->id)->value('original_name_ciphertext'))->toBe($snapshot['original_name_ciphertext']);
});

test('retains a private orphan when database metadata fails to commit', function () {
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('rollback.png'), PrivateUploadPurpose::RefundEvidence);
    try {
        DB::transaction(function () use ($stored): void {
            RefundEvidence::factory()->create([
                'private_path' => $stored['private_path'],
                'original_name_ciphertext' => $stored['original_name'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'sha256' => $stored['sha256'],
            ]);
            throw new RuntimeException('Synthetic metadata commit failure.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Synthetic metadata commit failure.');
    }
    $this->assertDatabaseCount('refund_evidence', 0);
    expect(Storage::disk('private_uploads')->exists($stored['private_path']))->toBeTrue();
    app(PrivateUploadStorage::class)->assertReadable($stored);
    $this->get('/storage/'.$stored['private_path'])->assertForbidden();
});

test('detects lost committed proof without deleting its metadata or completing a transfer', function () {
    $stored = app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('proof.png'), PrivateUploadPurpose::RefundEvidence);
    $evidence = RefundEvidence::factory()->create([
        'private_path' => $stored['private_path'],
        'original_name_ciphertext' => $stored['original_name'],
        'mime_type' => $stored['mime_type'],
        'size_bytes' => $stored['size_bytes'],
        'sha256' => $stored['sha256'],
    ]);
    Storage::disk('private_uploads')->delete($stored['private_path']);
    expect(fn () => app(PrivateUploadStorage::class)->assertReadable($stored))->toThrow(RuntimeException::class, 'missing');
    $this->assertModelExists($evidence);
    expect($evidence->refundTransfer->status)->toBe(RefundTransferStatus::Unknown);
});

test('throws on real filesystem write failure before returning any metadata', function () {
    $root = config('filesystems.private_upload_test_root');
    File::ensureDirectoryExists($root);
    File::put($root.'/blocked', 'This file cannot be a storage directory.');
    config(['filesystems.disks.private_uploads.root' => $root.'/blocked']);
    Storage::forgetDisk('private_uploads');
    expect(fn () => app(PrivateUploadStorage::class)->store(UploadedFile::fake()->image('proof.png'), PrivateUploadPurpose::RefundEvidence))
        ->toThrow(UnableToCreateDirectory::class);
    $this->assertDatabaseCount('refund_evidence', 0);
});
