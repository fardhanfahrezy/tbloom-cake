<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return Gate::authorize($product ? 'update' : 'create', $product ?? Product::class)->allowed();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'category' => ['required', Rule::enum(ProductCategory::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'sales_unit_label' => ['required', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
            'archived' => ['required', 'boolean'],
        ];
    }
}
