<?php

namespace App;

use App\Models\PickupMethod;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CheckoutDetails
{
    public const COURIER_POLICY_VERSION = 'courier-v1';

    public const COURIER_POLICY_TEXT = 'Pemesanan kurir dan ongkir menjadi tanggung jawab customer. Kerusakan setelah cake diserahkan ke kurir bukan tanggung jawab TBloom Cake.';

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function normalize(array $input): array
    {
        if (isset($input['inscription']) && is_string($input['inscription'])) {
            $input['inscription'] = trim(preg_replace('/[\\s\\p{Z}]+/u', ' ', $input['inscription']) ?? $input['inscription']);
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(?CarbonImmutable $instant = null): array
    {
        $window = $instant ? BookingCalendar::simpleWindow($instant) : null;

        return [
            'product_id' => ['bail', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'product_variant_id' => ['bail', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'quantity' => ['bail', 'required', 'integer', 'min:1', 'max:2147483647'],
            'flavor_code' => ['bail', 'required', 'string', 'max:64', ...($instant ? [Rule::in(array_keys(config('checkout.flavors', [])))] : [])],
            'filling_code' => ['bail', 'required', 'string', 'max:64', ...($instant ? [Rule::in(array_keys(config('checkout.fillings', [])))] : [])],
            'cover_color' => ['bail', 'required', 'string', 'max:100'],
            'inscription_choice' => ['bail', 'required', Rule::in(['text', 'none'])],
            'inscription' => ['bail', 'nullable', 'string', 'required_if:inscription_choice,text', 'prohibited_if:inscription_choice,none', 'max:150',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $words = preg_split('/[\\s\\p{Z}]+/u', $value, flags: PREG_SPLIT_NO_EMPTY);
                    if ($words === false || count($words) > 10) {
                        $fail('Tulisan maksimal sepuluh kata dengan teks yang valid.');
                    }
                }],
            'pickup_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', ...($window ? ['after_or_equal:'.$window['earliest']->toDateString(), 'before_or_equal:'.$window['latest']->toDateString()] : [])],
            'pickup_time' => ['bail', 'required', 'string', 'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value < '09:00' || $value > '21:00') {
                        $fail('Pilih jam pengambilan antara 09.00 dan 21.00 WIB dengan presisi menit.');
                    }
                }],
            'pickup_method' => ['bail', 'required', Rule::enum(PickupMethod::class)],
            'handover_note' => ['bail', 'nullable', 'string', 'max:255'],
            'courier_terms_accepted' => ['exclude_unless:pickup_method,online_courier', 'accepted'],
            'idempotency_key' => ['bail', 'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function canonicalInput(array $input): array
    {
        $data = Validator::make(self::normalize($input), self::rules(), self::messages())->validate();
        foreach (['product_id', 'product_variant_id', 'quantity'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $data['inscription'] = $data['inscription_choice'] === 'none' ? '' : $data['inscription'];
        $data['handover_note'] = $data['handover_note'] ?? null;
        $data['courier_terms_accepted'] = $data['pickup_method'] === PickupMethod::OnlineCourier->value;
        ksort($data);

        return $data;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'pickup_date.*' => 'Pilih tanggal pengambilan dalam rentang yang tersedia.',
            'pickup_time.*' => 'Pilih jam pengambilan antara 09.00 dan 21.00 WIB dengan presisi menit.',
            'flavor_code.*' => 'Pilih rasa yang tersedia.',
            'filling_code.*' => 'Pilih filling yang tersedia.',
            'inscription_choice.*' => 'Pilih tulisan atau tanpa tulisan secara eksplisit.',
            'courier_terms_accepted.*' => 'Setujui kebijakan pengambilan oleh kurir sebelum melanjutkan.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{product_id: int, product_variant_id: int, quantity: int, flavor_code: string, filling_code: string,
     *     cover_color: string, inscription_choice: string, inscription: string, pickup_date: string, pickup_time: string,
     *     pickup_method: string, handover_note: ?string, idempotency_key: string, courier_terms_accepted: bool,
     *     flavor_label_snapshot: string, filling_label_snapshot: string, policy_version: ?string,
     *     policy_text_snapshot: ?string, courier_terms_accepted_at: ?string}
     */
    public function validate(array $input, CarbonImmutable $instant): array
    {
        $data = Validator::make(self::normalize($input), self::rules($instant), self::messages())->validate();
        foreach (['product_id', 'product_variant_id', 'quantity'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $courier = $data['pickup_method'] === PickupMethod::OnlineCourier->value;
        $data['inscription'] = $data['inscription_choice'] === 'none' ? '' : $data['inscription'];
        $data['pickup_time'] .= ':00';
        $data['handover_note'] = $data['handover_note'] ?? null;
        $data['flavor_label_snapshot'] = config('checkout.flavors')[$data['flavor_code']];
        $data['filling_label_snapshot'] = config('checkout.fillings')[$data['filling_code']];
        $data['courier_terms_accepted'] = $courier;
        $data['policy_version'] = $courier ? self::COURIER_POLICY_VERSION : null;
        $data['policy_text_snapshot'] = $courier ? self::COURIER_POLICY_TEXT : null;
        $data['courier_terms_accepted_at'] = $courier ? $instant->setTimezone('UTC')->startOfSecond()->format('Y-m-d H:i:s') : null;

        return $data;
    }
}
