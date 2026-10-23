<?php

namespace App;

use finfo;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class PrivateUploadStorage
{
    public const MAX_SIZE_BYTES = 5242880;

    public function __construct(private FilesystemManager $filesystems) {}

    /**
     * Internal metadata only; persist original_name through the model's encrypted filename cast.
     *
     * @return array{private_path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}
     */
    public function store(UploadedFile $upload, PrivateUploadPurpose $purpose): array
    {
        Validator::make(['upload' => $upload], ['upload' => ['required', 'file', 'max:5120']])->validate();

        $bytes = file_get_contents($upload->getRealPath());
        if ($bytes === false) {
            throw new RuntimeException('Private upload could not be read.');
        }

        $size = strlen($bytes);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($size < 1 || $size > self::MAX_SIZE_BYTES || ! in_array($mime, $purpose->mimeTypes(), true)
            || (str_starts_with($mime, 'image/') && @getimagesizefromstring($bytes) === false)) {
            throw ValidationException::withMessages(['upload' => 'The upload must contain an approved file type and be at most 5 MB.']);
        }

        $disk = $this->filesystems->disk('private_uploads');
        $path = $purpose->value.'/'.Str::uuid();
        if ($disk->exists($path) || ! $disk->put($path, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Private upload could not be stored at a new object key.');
        }

        $metadata = [
            'private_path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash('sha256', $bytes),
        ];
        $this->assertReadable($metadata);

        return $metadata;
    }

    /** @param array{private_path: string, size_bytes: int, sha256: string} $metadata */
    public function assertReadable(array $metadata): void
    {
        $parts = explode('/', $metadata['private_path']);
        if (count($parts) !== 2 || PrivateUploadPurpose::tryFrom($parts[0]) === null || ! Str::isUuid($parts[1])
            || $metadata['size_bytes'] < 1 || $metadata['size_bytes'] > self::MAX_SIZE_BYTES
            || ! preg_match('/\A[a-f0-9]{64}\z/', $metadata['sha256'])) {
            throw new InvalidArgumentException('Invalid private object metadata.');
        }

        $disk = $this->filesystems->disk('private_uploads');
        if (! $disk->exists($metadata['private_path'])) {
            throw new RuntimeException('Private upload is missing.');
        }

        $bytes = $disk->get($metadata['private_path']);
        if (strlen($bytes) !== $metadata['size_bytes'] || ! hash_equals($metadata['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('Private upload no longer matches its verified metadata.');
        }
    }
}
