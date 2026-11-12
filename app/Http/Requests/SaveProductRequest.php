<?php
namespace App\Http\Requests;
use App\Models\Product;
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
    public function rules(): array
    {
        $product = $this->route('product');
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('products', 'slug')->ignore($product)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true)->when($product, fn ($query) => $query->orWhere('id', $product->category_id)))],
            'description' => ['nullable', 'string', 'max:10000'], 'sales_unit_label' => ['required', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'], 'archived' => ['required', 'boolean'],
        ];
    }
}
