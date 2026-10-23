<?php

namespace App;

use finfo;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CatalogImageStorage
{
    public const MAX_BYTES = 5242880;

    public function __construct(private FilesystemManager $filesystems) {}

    public function store(UploadedFile $upload): string
    {
        $bytes = file_get_contents($upload->getRealPath());
        $dimensions = $bytes === false ? false : @getimagesizefromstring($bytes);
        $mime = $bytes === false ? false : (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > self::MAX_BYTES
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! $dimensions
            || $dimensions[0] < 1 || $dimensions[1] < 1 || max($dimensions[0], $dimensions[1]) > 4096
            || $dimensions[0] * $dimensions[1] > 12000000) {
            throw ValidationException::withMessages(['image' => 'Gunakan gambar JPEG, PNG, atau WebP maksimal 5 MB, sisi 4096 piksel, dan 12 megapiksel.']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'Isi gambar tidak dapat dibaca.']);
        }
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);
        $ratio = min(1, 1600 / max($dimensions[0], $dimensions[1]));
        if ($ratio < 1) {
            $image = imagescale($image, max(1, (int) floor($dimensions[0] * $ratio)), max(1, (int) floor($dimensions[1] * $ratio)));
            if ($image === false) {
                throw new RuntimeException('Product image resizing failed.');
            }
            imagesavealpha($image, true);
        }
        ob_start();
        try {
            if (! imagewebp($image, null, 85)) {
                throw new RuntimeException('Product image encoding failed.');
            }
            $encoded = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (! is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_BYTES
            || (@getimagesizefromstring($encoded)['mime'] ?? null) !== 'image/webp') {
            throw new RuntimeException('Product image output verification failed.');
        }
        $disk = $this->filesystems->disk('public');
        $path = 'products/'.Str::uuid().'.webp';
        if ($disk->exists($path)) {
            throw new RuntimeException('Product image object key already exists.');
        }
        try {
            if (! $disk->put($path, $encoded, ['visibility' => 'public'])
                || ! hash_equals(hash('sha256', $encoded), hash('sha256', $disk->get($path)))) {
                throw new RuntimeException('Product image storage verification failed.');
            }
        } catch (Throwable $exception) {
            $this->discard($path);
            throw $exception;
        }

        return $path;
    }

    public function discard(string $path): void
    {
        if (! preg_match('/\Aproducts\/[a-zA-Z0-9._-]+\.(?:webp|png|jpe?g)\z/', $path)) {
            return;
        }
        try {
            if (! $this->filesystems->disk('public')->delete($path)) {
                report(new RuntimeException('Unused product image cleanup failed.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
