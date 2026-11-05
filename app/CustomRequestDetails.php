<?php

namespace App;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomRequestDetails
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function normalize(array $input): array
    {
        foreach (['size', 'color', 'inscription', 'fondant_details', 'notes'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $input[$field]) ?? $input[$field]);
            }
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'pickup_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'size' => ['bail', 'required', 'string', 'max:100'],
            'color' => ['bail', 'required', 'string', 'max:100'],
            'flavor_code' => ['bail', 'required', 'string', 'max:64'],
            'filling_code' => ['bail', 'required', 'string', 'max:64'],
            'inscription_choice' => ['bail', 'required', Rule::in(['none', 'text'])],
            'inscription' => ['bail', 'nullable', 'string', 'required_if:inscription_choice,text', 'prohibited_if:inscription_choice,none', 'max:150'],
            'edible_print' => ['required', 'boolean'],
            'fondant' => ['required', 'boolean'],
            'fondant_details' => ['bail', 'nullable', 'string', 'required_if:fondant,1,true', 'prohibited_if:fondant,0,false', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['bail', 'required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'attachments' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'attachments.*' => ['bail', 'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'pickup_date.*' => 'Pilih tanggal minimal H-3, atau H-7 dengan edible print, sampai D+90 kalender Jakarta.',
            'inscription_choice.*' => 'Pilih tulisan atau tanpa tulisan secara eksplisit.',
            'fondant_details.required_if' => 'Jelaskan detail fondant yang diminta.',
            'attachments.*' => 'Unggah satu sampai tiga gambar referensi privat.',
            'attachments.*.*' => 'Referensi harus berupa gambar JPEG, PNG, atau WebP maksimal 5 MB per file.',
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function canonicalInput(array $input): array
    {
        $data = Validator::make(self::normalize($input), self::rules(), self::messages())->validate();
        $data['edible_print'] = (bool) $data['edible_print'];
        $data['fondant'] = (bool) $data['fondant'];
        $data['fondant_details'] = $data['fondant'] ? $data['fondant_details'] : null;
        $data['inscription'] = $data['inscription_choice'] === 'none' ? '' : $data['inscription'];
        $data['notes'] = $data['notes'] ?? null;
        ksort($data);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function validateCurrent(array $data, CarbonImmutable $instant): void
    {
        $window = BookingCalendar::customWindow($instant, $data['edible_print']);
        Validator::make(Arr::only($data, ['pickup_date', 'flavor_code', 'filling_code']), [
            'pickup_date' => ['after_or_equal:'.$window['earliest']->toDateString(), 'before_or_equal:'.$window['latest']->toDateString()],
            'flavor_code' => [Rule::in(array_keys(config('checkout.flavors', [])))],
            'filling_code' => [Rule::in(array_keys(config('checkout.fillings', [])))],
        ], self::messages())->validate();
    }
}
