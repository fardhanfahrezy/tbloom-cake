<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class RefundPrivateData
{
    public function __construct(private PrivateUploadStorage $uploads) {}

    /** @return array<string, list<string>> */
    public static function bankRules(): array
    {
        return ['bank_name' => ['required', 'string', 'max:100', 'regex:/\S/u', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'account_number' => ['required', 'string', 'max:100', 'regex:/\S/u', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'account_holder' => ['required', 'string', 'max:255', 'regex:/\S/u', 'not_regex:/[\x00-\x1F\x7F]/u']];
    }

    /** @return array<string, list<string>> */
    public static function verificationRules(): array
    {
        return AssignRefund::versionRules() + ['bank_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'customer_confirmed' => ['required', 'accepted']];
    }

    /** @return array<string, mixed> */
    public function bank(int $refundId, User $actor): array
    {
        return $this->locked($refundId, $actor, function (RefundLog $refund, User $current): array {
            Gate::forUser($current)->authorize('privateData', $refund);
            $this->audit($refund, $current, 'refund.bank_viewed');

            return ['bank_name' => $refund->bank_name, 'account_number' => $refund->account_number,
                'account_holder' => $refund->account_holder, 'bank_token' => $this->bankToken($refund),
                'bank_verified_at' => $refund->bank_verified_at?->toISOString()];
        });
    }

    /** @param array<string, mixed> $input */
    public function saveBank(int $refundId, User $actor, array $input, ?int $version = null): bool
    {
        $bank = Validator::make($input, self::bankRules())->validate();
        $bank = array_map(trim(...), $bank);

        return $this->locked($refundId, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($bank, $version): bool {
            Gate::forUser($current)->authorize('privateData', $refund);
            if ($current->role !== UserRole::Customer) {
                $this->work($refund, $current, $version);
            }
            if ($refund->status === RefundLogStatus::Completed || $this->activeAttempt($transfers)) {
                return false;
            }
            if (collect($bank)->every(fn (string $value, string $field): bool => $value === $refund->$field)) {
                return true;
            }
            $refund->forceFill($bank + ['bank_verified_at' => null, 'bank_verified_by' => null])->save();
            $this->audit($refund, $current, 'refund.bank_updated');

            return true;
        });
    }

    /** @param array<string, mixed> $input */
    public function verifyBank(int $refundId, User $actor, array $input): bool
    {
        $data = Validator::make($input, self::verificationRules())->validate();

        return $this->locked($refundId, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($data): bool {
            $this->work($refund, $current, (int) $data['assignment_version']);
            if ($this->activeAttempt($transfers) || $refund->bank_name === null || $refund->account_number === null || $refund->account_holder === null
                || ! hash_equals($this->bankToken($refund), $data['bank_token'])) {
                return false;
            }
            if ($refund->bank_verified_by === $current->id && $refund->bank_verified_at !== null) {
                return true;
            }
            $refund->forceFill(['bank_verified_at' => CarbonImmutable::now('UTC')->startOfSecond(), 'bank_verified_by' => $current->id])->save();
            $this->audit($refund, $current, 'refund.bank_verified', ['customer_confirmed' => true]);

            return true;
        });
    }

    public function upload(int $refundId, int $transferId, User $actor, int $version, UploadedFile $upload): ?RefundEvidence
    {
        $this->independentTransaction();
        Validator::make(['assignment_version' => $version, 'upload' => $upload], AssignRefund::versionRules() + ['upload' => ['required', 'file', 'max:5120']])->validate();
        $digest = hash_file('sha256', $upload->getRealPath());
        $preflight = $this->locked($refundId, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($transferId, $version, $digest): ?array {
            $this->work($refund, $current, $version);
            $transfer = $transfers->firstWhere('id', $transferId);
            abort_if($transfer === null, 404);
            if (! in_array($transfer->status, [RefundTransferStatus::Prepared, RefundTransferStatus::Unknown], true)) {
                return null;
            }

            return ['existing' => RefundEvidence::query()->where('refund_transfer_id', $transfer->id)->where('uploaded_by', $current->id)->where('sha256', $digest)->first()];
        });
        if ($preflight === null) {
            return null;
        }
        $existing = $preflight['existing'];
        $metadata = null;
        if ($existing !== null) {
            $this->readEvidence($existing);
        } else {
            $metadata = $this->uploads->store($upload, PrivateUploadPurpose::RefundEvidence);
            $this->uploads->assertReadable($metadata);
        }

        return $this->locked($refundId, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($transferId, $version, $digest, $existing, $metadata): ?RefundEvidence {
            $this->work($refund, $current, $version);
            $transfer = $transfers->firstWhere('id', $transferId);
            abort_if($transfer === null, 404);
            if (! in_array($transfer->status, [RefundTransferStatus::Prepared, RefundTransferStatus::Unknown], true)) {
                return null;
            }
            $duplicate = RefundEvidence::query()->where('refund_transfer_id', $transfer->id)->where('uploaded_by', $current->id)->where('sha256', $digest)->lockForUpdate()->first();
            if ($duplicate !== null || $existing !== null) {
                return $duplicate !== null && $existing !== null && $duplicate->getRawOriginal() === $existing->getRawOriginal() ? $duplicate : null;
            }
            $evidence = new RefundEvidence;
            $evidence->forceFill(['refund_log_id' => $refund->id, 'refund_transfer_id' => $transfer->id, 'uploaded_by' => $current->id,
                'private_path' => $metadata['private_path'], 'original_name_ciphertext' => $metadata['original_name'],
                'mime_type' => $metadata['mime_type'], 'size_bytes' => $metadata['size_bytes'], 'sha256' => $metadata['sha256']])->save();
            $this->audit($refund, $current, 'refund.evidence_uploaded', ['evidence_id' => $evidence->id, 'refund_transfer_id' => $transfer->id]);

            return $evidence;
        });
    }

    /** @return array{bytes: string, mime_type: string, filename: string} */
    public function download(int $evidenceId, User $actor): array
    {
        $snapshot = RefundEvidence::query()->findOrFail($evidenceId);
        $evidence = $this->locked($snapshot->refund_log_id, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($evidenceId): RefundEvidence {
            Gate::forUser($current)->authorize('privateData', $refund);
            $evidence = RefundEvidence::query()->whereKey($evidenceId)->where('refund_log_id', $refund->id)->lockForUpdate()->firstOrFail();
            abort_unless($transfers->contains('id', $evidence->refund_transfer_id), 404);

            return $evidence;
        });
        $bytes = $this->readEvidence($evidence);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$evidence->mime_type] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Private evidence has an unsupported content type.');
        }

        return $this->locked($evidence->refund_log_id, $actor, function (RefundLog $refund, User $current, Collection $transfers) use ($evidence, $bytes, $extension): array {
            Gate::forUser($current)->authorize('privateData', $refund);
            $currentEvidence = RefundEvidence::query()->whereKey($evidence->id)->where('refund_log_id', $refund->id)->lockForUpdate()->firstOrFail();
            abort_unless($transfers->contains('id', $currentEvidence->refund_transfer_id) && $currentEvidence->getRawOriginal() === $evidence->getRawOriginal(), 409);
            $this->audit($refund, $current, 'refund.evidence_downloaded', ['evidence_id' => $evidence->id]);

            return ['bytes' => $bytes, 'mime_type' => $evidence->mime_type, 'filename' => 'refund-evidence-'.$evidence->id.'.'.$extension];
        });
    }

    public function readEvidence(RefundEvidence $evidence): string
    {
        $this->independentTransaction();
        if (! str_starts_with($evidence->private_path, PrivateUploadPurpose::RefundEvidence->value.'/')) {
            throw new RuntimeException('Evidence object has an invalid purpose.');
        }
        $this->uploads->assertReadable(['private_path' => $evidence->private_path, 'size_bytes' => $evidence->size_bytes, 'sha256' => $evidence->sha256]);
        $bytes = Storage::disk('private_uploads')->get($evidence->private_path);
        if (strlen($bytes) !== $evidence->size_bytes || ! hash_equals($evidence->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Evidence bytes changed during reading.');
        }

        return $bytes;
    }

    /** @param Closure(RefundLog, User, Collection<int, RefundTransfer>): mixed $operation */
    private function locked(int $refundId, User $actor, Closure $operation): mixed
    {
        $this->independentTransaction();
        $connection = DB::connection();
        $session = $connection->selectOne('SELECT @@session.transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($connection->getDriverName() !== 'mysql' || $session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Private refund operations require READ COMMITTED and UTC.');
        }
        $snapshot = RefundLog::query()->with('order')->findOrFail($refundId);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction(function () use ($snapshot, $actor, $operation): mixed {
                    $users = User::query()->whereIn('id', array_filter([$actor->id, $snapshot->order->user_id, $snapshot->assigned_admin_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
                    $payment = Payment::query()->whereKey($snapshot->payment_id)->lockForUpdate()->firstOrFail();
                    $refund = RefundLog::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                    $transfers = RefundTransfer::query()->where('refund_log_id', $refund->id)->orderBy('id')->lockForUpdate()->get();
                    $current = $users->get($actor->id);
                    abort_unless((int) Auth::id() === $actor->id && $current !== null && $current->is_active, 403);
                    abort_unless($refund->order_id === $order->id && $refund->payment_id === $payment->id && $payment->order_id === $order->id
                        && $order->user_id === $snapshot->order->user_id, 409);
                    $refund->setRelation('order', $order);

                    return $operation($refund, $current, $transfers);
                });
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
            }
        }
    }

    private function independentTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Private refund operations cannot run inside a caller transaction.');
        }
    }

    private function work(RefundLog $refund, User $current, ?int $version): void
    {
        Gate::forUser($current)->authorize('privateData', $refund);
        abort_unless(in_array($current->role, [UserRole::Admin, UserRole::Owner], true), 403);
        $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
        abort_unless($time >= '09:00:00' && $time <= '17:00:00', 403);
        abort_unless($refund->assigned_admin_id === $current->id && $refund->assignment_version === $version && $refund->status !== RefundLogStatus::Completed, 409);
    }

    /** @param Collection<int, RefundTransfer> $transfers */
    private function activeAttempt(Collection $transfers): bool
    {
        return $transfers->contains(fn (RefundTransfer $transfer): bool => in_array($transfer->status, [RefundTransferStatus::Prepared, RefundTransferStatus::Unknown], true));
    }

    private function bankToken(RefundLog $refund): string
    {
        return hash_hmac('sha256', json_encode([$refund->id, $refund->bank_name, $refund->account_number, $refund->account_holder], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** @param array<string, mixed> $extra */
    private function audit(RefundLog $refund, User $actor, string $action, array $extra = []): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'refund_log', 'entity_id' => $refund->id,
            'action' => $action, 'after_redacted' => ['assignment_version' => $refund->assignment_version] + $extra, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
