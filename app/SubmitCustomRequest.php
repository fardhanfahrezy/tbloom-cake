<?php

namespace App;

use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestAttachment;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class SubmitCustomRequest
{
    public function __construct(private CustomRequestDetails $details, private PrivateUploadStorage $uploads, private CustomRequestEvents $events) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): CustomCakeRequest
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Custom submission requires an independent MySQL transaction.');
        }
        $session = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Custom submission requires READ COMMITTED and UTC.');
        }
        $customer = User::query()->find($actor->id);
        $this->authorize($customer);
        $data = $this->details->canonicalInput($input);
        $hashInput = Arr::except($data, ['idempotency_key', 'attachments']);
        $hashInput['attachments'] = array_map(fn ($upload): string => hash_file('sha256', $upload->getRealPath()), $data['attachments']);
        $hash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR));
        $preflight = DB::transaction(fn (): ?CustomCakeRequest => $this->existing($actor, $data, $hash), 3);
        if ($preflight) {
            return $preflight;
        }
        $staged = [];
        foreach ($data['attachments'] as $upload) {
            $staged[] = $this->uploads->store($upload, PrivateUploadPurpose::CustomReference);
        }

        return DB::transaction(function () use ($actor, $data, $hash, $staged): CustomCakeRequest {
            $existing = $this->existing($actor, $data, $hash);
            if ($existing) {
                return $existing;
            }
            $customer = User::query()->findOrFail($actor->id);
            $custom = new CustomCakeRequest;
            $custom->forceFill(Arr::except($data, ['attachments', 'inscription_choice']) + [
                'request_number' => 'TC-'.Str::uuid(), 'user_id' => $customer->id, 'status' => 'submitted',
                'customer_name_snapshot' => $customer->name, 'whatsapp_snapshot' => $customer->whatsapp_number,
                'option_labels_snapshot' => ['flavor' => config('checkout.flavors')[$data['flavor_code']], 'filling' => config('checkout.fillings')[$data['filling_code']]],
                'request_hash' => $hash,
            ])->save();
            foreach ($staged as $metadata) {
                $attachment = new CustomCakeRequestAttachment;
                $attachment->forceFill(Arr::except($metadata, ['original_name']) + [
                    'custom_cake_request_id' => $custom->id, 'original_name_ciphertext' => $metadata['original_name'], 'uploaded_by' => $customer->id,
                ])->save();
            }
            $this->events->record($custom, $customer, 'submitted', [], ['status' => 'submitted', 'attachment_count' => count($staged)]);

            return $custom;
        }, 3);
    }

    private function authorize(?User $customer): void
    {
        if (! $customer || ! $customer->is_active || $customer->role !== UserRole::Customer) {
            throw new AuthorizationException('Request custom hanya untuk customer aktif.');
        }
    }

    /** @param array<string, mixed> $data */
    private function existing(User $actor, array $data, string $hash): ?CustomCakeRequest
    {
        $customer = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        $this->authorize($customer);
        $existing = CustomCakeRequest::query()->where('user_id', $customer->id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci request telah digunakan untuk detail berbeda.');

            return $existing;
        }
        $this->details->validateCurrent($data, CarbonImmutable::now('UTC'));

        return null;
    }
}
