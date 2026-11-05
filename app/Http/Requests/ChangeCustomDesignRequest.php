<?php

namespace App\Http\Requests;

use App\CustomRequestWorkflow;
use Illuminate\Foundation\Http\FormRequest;

class ChangeCustomDesignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('customRequest')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomRequestWorkflow::normalize($this->all()));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return CustomRequestWorkflow::changeRules();
    }
}
