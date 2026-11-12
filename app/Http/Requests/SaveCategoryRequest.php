<?php
namespace App\Http\Requests;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');
        return Gate::authorize($category ? 'update' : 'create', $category ?? Category::class)->allowed();
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('categories', 'slug')->ignore($this->route('category'))],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
