<?php
namespace App\Http\Controllers;
use App\CategoryManager;
use App\Http\Requests\SaveCategoryRequest;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
class CategoryAdminController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Category::class);
        return view('staff.categories.index', ['categories' => Category::query()->withCount('products')->orderBy('sort_order')->orderBy('name')->paginate(15)]);
    }
    public function create(): View
    {
        Gate::authorize('create', Category::class);
        return view('staff.categories.form', ['category' => new Category(['is_active' => true])]);
    }
    public function store(SaveCategoryRequest $request, CategoryManager $manager): RedirectResponse { return $this->save($request, $manager); }
    public function edit(Category $category): View
    {
        Gate::authorize('update', $category);
        return view('staff.categories.form', compact('category'));
    }
    public function update(SaveCategoryRequest $request, Category $category, CategoryManager $manager): RedirectResponse { return $this->save($request, $manager, $category); }
    public function destroy(Category $category, CategoryManager $manager): RedirectResponse
    {
        Gate::authorize('delete', $category);
        $manager->delete(request()->user(), $category);
        return to_route('staff.categories.index')->with('status', 'Kategori berhasil dihapus.');
    }
    private function save(SaveCategoryRequest $request, CategoryManager $manager, ?Category $category = null): RedirectResponse
    {
        try { $manager->save($request->user(), $request->validated(), $category); }
        catch (UniqueConstraintViolationException) { throw ValidationException::withMessages(['slug' => 'Slug kategori sudah digunakan.']); }
        return to_route('staff.categories.index')->with('status', 'Kategori berhasil disimpan.');
    }
}
