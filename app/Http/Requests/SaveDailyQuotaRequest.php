<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveDailyQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('update', $this->route('product'))->allowed();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'availability_date' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31'],
            'quota_limit' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }
}
