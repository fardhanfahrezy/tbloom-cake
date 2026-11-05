<?php

namespace App\Http\Requests;

use App\CustomRequestDetails;
use App\Models\CustomCakeRequest;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCustomRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CustomCakeRequest::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomRequestDetails::normalize($this->all()));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return CustomRequestDetails::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CustomRequestDetails::messages();
    }
}
