<?php

namespace App\Http\Requests;

use App\BookingCalendar;
use App\Models\ProductCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogBrowseRequest extends FormRequest
{
    protected $redirectRoute = 'catalog.index';

    /** @var array{earliest: CarbonImmutable, latest: CarbonImmutable}|null */
    private ?array $window = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array{earliest: CarbonImmutable, latest: CarbonImmutable} */
    public function window(): array
    {
        return $this->window ??= BookingCalendar::simpleWindow(CarbonImmutable::now());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $window = $this->window();

        return [
            'pickup_date' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:'.$window['earliest']->toDateString(), 'before_or_equal:'.$window['latest']->toDateString()],
            'category' => ['nullable', Rule::enum(ProductCategory::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['pickup_date.*' => 'Pilih tanggal pengambilan dalam rentang yang tersedia.', 'category.*' => 'Pilih kategori yang tersedia.'];
    }

    public function pickupDate(): string
    {
        return $this->validated('pickup_date') ?? $this->window()['earliest']->toDateString();
    }
}
