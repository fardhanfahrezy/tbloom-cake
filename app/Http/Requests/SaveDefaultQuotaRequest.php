<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveDefaultQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('update', $this->route('product'))->allowed();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'default_daily_quota' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'default_reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }
}
