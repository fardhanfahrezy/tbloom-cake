<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('update', $this->route('product'))->allowed();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'image' => [$this->route('image') ? 'nullable' : 'required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            'alt_text' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }
}
