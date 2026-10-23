<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('update', $this->route('product'))->allowed();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'size_label' => ['required', 'string', 'max:100', Rule::unique('product_variants', 'size_label')->where('product_id', $this->route('product')->id)->ignore($this->route('variant'))],
            'price' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
