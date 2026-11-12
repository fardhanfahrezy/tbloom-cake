<?php
namespace App\Http\Requests;
use App\BookingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class CatalogBrowseRequest extends FormRequest
{
    protected $redirectRoute = 'catalog.index';
    private ?array $window = null;
    public function authorize(): bool { return true; }
    public function window(): array { return $this->window ??= BookingCalendar::simpleWindow(CarbonImmutable::now()); }
    public function rules(): array
    {
        $window = $this->window();
        return ['pickup_date' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:'.$window['earliest']->toDateString(), 'before_or_equal:'.$window['latest']->toDateString()],
            'category' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)]];
    }
    public function messages(): array { return ['pickup_date.*' => 'Pilih tanggal pengambilan dalam rentang yang tersedia.', 'category.*' => 'Pilih kategori yang tersedia.']; }
    public function pickupDate(): string { return $this->validated('pickup_date') ?? $this->window()['earliest']->toDateString(); }
}
