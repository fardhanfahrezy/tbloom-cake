<?php

namespace App;

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use LogicException;

class CustomRequestAttachments
{
    public function __construct(private PrivateUploadStorage $uploads) {}

    /** @return array{bytes: string, mime_type: string, filename: string} */
    public function download(int $id, User $actor): array
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new LogicException('Private downloads require an independent MySQL transaction.');
        }
        $snapshot = CustomCakeRequestAttachment::query()->findOrFail($id);
        $attachment = DB::transaction(fn (): CustomCakeRequestAttachment => $this->authorized($snapshot, $actor), 3);
        abort_unless(str_starts_with($attachment->private_path, PrivateUploadPurpose::CustomReference->value.'/'), 409);
        $this->uploads->assertReadable(['private_path' => $attachment->private_path, 'size_bytes' => $attachment->size_bytes, 'sha256' => $attachment->sha256]);
        $bytes = Storage::disk('private_uploads')->get($attachment->private_path);
        abort_unless(strlen($bytes) === $attachment->size_bytes && hash_equals($attachment->sha256, hash('sha256', $bytes)), 409);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$attachment->mime_type] ?? null;
        abort_if($extension === null, 409);

        return DB::transaction(function () use ($attachment, $actor, $bytes, $extension): array {
            $current = $this->authorized($attachment, $actor);
            abort_unless($current->getRawOriginal() === $attachment->getRawOriginal(), 409);

            return ['bytes' => $bytes, 'mime_type' => $attachment->mime_type, 'filename' => 'custom-reference-'.$attachment->id.'.'.$extension];
        }, 3);
    }

    private function authorized(CustomCakeRequestAttachment $snapshot, User $actor): CustomCakeRequestAttachment
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $parent = CustomCakeRequest::query()->whereKey($snapshot->custom_cake_request_id)->lockForUpdate()->firstOrFail();
        $attachment = CustomCakeRequestAttachment::query()->whereKey($snapshot->id)
            ->where('custom_cake_request_id', $parent->id)->lockForUpdate()->firstOrFail();
        $attachment->setRelation('request', $parent);
        Gate::forUser($current)->authorize('view', $attachment);

        return $attachment;
    }
}
