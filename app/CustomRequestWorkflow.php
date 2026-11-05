<?php

namespace App;

use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestStatus;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class CustomRequestWorkflow
{
    private const DESIGN_FIELDS = ['size', 'flavor_code', 'filling_code', 'color', 'inscription', 'edible_print', 'fondant', 'fondant_details', 'notes'];

    public function __construct(private CustomRequestEvents $events) {}

    /** @return array<string, list<mixed>> */
    public static function operationRules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'idempotency_key' => CustomRequestDetails::rules()['idempotency_key'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function transitionRules(): array
    {
        return self::operationRules() + ['status' => ['required', Rule::in(['contacted', 'quoted', 'accepted', 'rejected'])]];
    }

    /** @return array<string, list<mixed>> */
    public static function changeRules(): array
    {
        return self::operationRules() + ['changes' => ['required', 'array:'.implode(',', [...self::DESIGN_FIELDS, 'inscription_choice']), 'min:1']];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function normalize(array $input): array
    {
        if (isset($input['reason']) && is_string($input['reason'])) {
            $input['reason'] = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $input['reason']) ?? $input['reason']);
        }
        if (isset($input['changes']) && is_array($input['changes'])) {
            $input['changes'] = CustomRequestDetails::normalize($input['changes']);
        }

        return $input;
    }

    public function version(CustomCakeRequest $custom): string
    {
        $state = Arr::only($custom->attributesToArray(), [...self::DESIGN_FIELDS, 'option_labels_snapshot', 'status', 'assigned_admin_id', 'status_reason', 'pickup_date', 'user_id', 'request_hash']);
        $state['audit_id'] = AuditLog::query()->where('entity_type', 'custom_request')->where('entity_id', $custom->id)->max('id');

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $input */
    public function transition(int $id, User $actor, array $input): CustomCakeRequest
    {
        $data = Validator::make(self::normalize($input), self::transitionRules())->validate();

        return $this->mutate($id, $actor, $data, 'status_changed', function (CustomCakeRequest $custom, User $current) use ($data): array {
            $allowed = match ($custom->status) {
                CustomCakeRequestStatus::Submitted => ['contacted', 'rejected'],
                CustomCakeRequestStatus::Contacted => ['quoted', 'rejected'],
                CustomCakeRequestStatus::Quoted => ['accepted', 'rejected'],
                default => [],
            };
            abort_unless(in_array($data['status'], $allowed, true), 409, 'Transisi status custom tidak tersedia.');
            $before = ['status' => $custom->status->value, 'assigned_admin_id' => $custom->assigned_admin_id];
            $custom->forceFill(['status' => $data['status'], 'assigned_admin_id' => $custom->assigned_admin_id ?? $current->id,
                'status_reason' => $data['status'] === 'rejected' ? $data['reason'] : null])->save();

            return [$before, ['status' => $custom->status->value, 'assigned_admin_id' => $custom->assigned_admin_id]];
        });
    }

    /** @param array<string, mixed> $input */
    public function change(int $id, User $actor, array $input): CustomCakeRequest
    {
        $data = Validator::make(self::normalize($input), self::changeRules())->validate();
        $data['changes'] = $this->canonicalChanges($data['changes']);

        return $this->mutate($id, $actor, $data, 'design_changed', function (CustomCakeRequest $custom) use ($data): array {
            $now = CarbonImmutable::now('Asia/Jakarta')->startOfSecond();
            $pickup = CarbonImmutable::parse($custom->pickup_date->toDateString(), 'Asia/Jakarta');
            if ($now->gt($pickup->subDays(2)->endOfDay())) {
                throw ValidationException::withMessages(['changes' => 'Perubahan desain hanya sampai akhir H-2 kalender Jakarta.']);
            }
            $changes = $data['changes'];
            if (($changes['edible_print'] ?? false) && ! $custom->edible_print && $pickup->lt($now->startOfDay()->addDays(7))) {
                throw ValidationException::withMessages(['changes.edible_print' => 'Menambah edible print memerlukan minimal H-7 kalender Jakarta.']);
            }
            $labels = $custom->option_labels_snapshot;
            foreach (['flavor_code' => ['flavor', 'checkout.flavors'], 'filling_code' => ['filling', 'checkout.fillings']] as $field => [$label, $configuration]) {
                if (isset($changes[$field]) && $changes[$field] !== $custom->{$field}) {
                    Validator::make([$field => $changes[$field]], [$field => [Rule::in(array_keys(config($configuration, [])))]])->validate();
                    $labels[$label] = config($configuration)[$changes[$field]];
                }
            }
            $before = Arr::only($custom->attributesToArray(), [...array_keys($changes), 'option_labels_snapshot']);
            $custom->forceFill($changes + ['option_labels_snapshot' => $labels])->save();
            $after = Arr::only($custom->attributesToArray(), [...array_keys($changes), 'option_labels_snapshot']);

            return [$before, $after];
        });
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function canonicalChanges(array $changes): array
    {
        if (array_key_exists('inscription', $changes) && ! array_key_exists('inscription_choice', $changes)) {
            throw ValidationException::withMessages(['changes.inscription_choice' => 'Pilih tulisan atau tanpa tulisan secara eksplisit.']);
        }
        if (array_key_exists('fondant_details', $changes) && ! array_key_exists('fondant', $changes)) {
            throw ValidationException::withMessages(['changes.fondant' => 'Pilih fondant secara eksplisit saat mengubah detailnya.']);
        }
        $fields = array_keys($changes);
        if (array_key_exists('inscription_choice', $changes)) {
            $fields[] = 'inscription';
        }
        if (array_key_exists('fondant', $changes)) {
            $fields[] = 'fondant_details';
        }
        $rules = Arr::only(CustomRequestDetails::rules(), $fields);
        $data = Validator::make($changes, $rules, CustomRequestDetails::messages())->validate();
        if (array_key_exists('inscription_choice', $data)) {
            $data['inscription'] = $data['inscription_choice'] === 'none' ? '' : $data['inscription'];
            unset($data['inscription_choice']);
        }
        foreach (['fondant', 'edible_print'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }
        if (array_key_exists('fondant', $data)) {
            $data['fondant_details'] = $data['fondant'] ? $data['fondant_details'] : null;
        }
        ksort($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(CustomCakeRequest, User): array{array<string, mixed>, array<string, mixed>}  $operation
     */
    private function mutate(int $id, User $actor, array $data, string $action, Closure $operation): CustomCakeRequest
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new LogicException('Custom changes require an independent MySQL transaction.');
        }
        $session = DB::selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($session->isolation_level !== 'READ-COMMITTED' || $session->time_zone !== '+00:00') {
            throw new LogicException('Custom changes require READ COMMITTED and UTC.');
        }
        $snapshot = CustomCakeRequest::query()->findOrFail($id);
        $keyHash = hash('sha256', $data['idempotency_key']);
        $operationHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($snapshot, $actor, $data, $action, $keyHash, $operationHash, $operation): CustomCakeRequest {
            $users = User::query()->whereIn('id', [$actor->id, $snapshot->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $custom = CustomCakeRequest::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($custom->user_id === $snapshot->user_id, 409);
            $current = $users->get($actor->id);
            if (! $current || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)) {
                throw new AuthorizationException('Pengelolaan custom hanya untuk staf aktif.');
            }
            $previous = AuditLog::query()->where('entity_type', 'custom_request')->where('entity_id', $custom->id)
                ->where('after_redacted->operation_key_hash', $keyHash)->first();
            if ($previous) {
                abort_unless($previous->actor_user_id === $current->id && $previous->action === 'custom_request.'.$action
                    && hash_equals($previous->after_redacted['operation_hash'], $operationHash), 409, 'Kunci operasi telah digunakan untuk perubahan berbeda.');

                return $custom;
            }
            $time = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
            if ($time < '09:00:00' || $time > '17:00:00') {
                throw new AuthorizationException('Perubahan manual hanya pada 09.00–17.00 WIB.');
            }
            abort_unless(hash_equals($this->version($custom), $data['expected_version']), 409, 'Request sudah berubah. Muat ulang detail terbaru.');
            abort_if(in_array($custom->status, [CustomCakeRequestStatus::Accepted, CustomCakeRequestStatus::Rejected], true), 409, 'Status terminal tidak dapat diubah.');
            $count = $custom->attachments()->count();
            abort_unless($count >= 1 && $count <= 3, 409, 'Referensi privat request belum lengkap.');
            [$before, $after] = $operation($custom, $current);
            $this->events->record($custom, $current, $action, $before, $after + ['operation_key_hash' => $keyHash, 'operation_hash' => $operationHash], $data['reason']);

            return $custom;
        }, 3);
    }
}
