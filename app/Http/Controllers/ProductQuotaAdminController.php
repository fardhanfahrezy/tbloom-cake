<?php

namespace App\Http\Controllers;

use App\CatalogManager;
use App\CatalogQuery;
use App\Http\Requests\SaveDailyQuotaRequest;
use App\Http\Requests\SaveDefaultQuotaRequest;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductQuotaAdminController extends Controller
{
    public function edit(Request $request, Product $product, CatalogQuery $catalog): View|RedirectResponse
    {
        Gate::authorize('update', $product);
        $validator = Validator::make($request->query(), ['date' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31']]);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                throw new ValidationException($validator);
            }

            return to_route('staff.products.quota.edit', $product)->withErrors($validator);
        }
        $date = $validator->validated()['date'] ?? CarbonImmutable::now('Asia/Jakarta')->toDateString();

        return view('staff.products.quota', ['product' => $product, 'date' => $date, 'capacity' => $catalog->availabilityFor($product, $date)]);
    }

    public function updateDefault(SaveDefaultQuotaRequest $request, Product $product, CatalogManager $catalog): RedirectResponse
    {
        $catalog->setDefaultQuota($request->user(), $product, $request->integer('default_daily_quota'), $request->validated('default_reason'));

        return to_route('staff.products.quota.edit', $product)->with('status', 'Kuota default berhasil diperbarui.');
    }

    public function updateDaily(SaveDailyQuotaRequest $request, Product $product, CatalogManager $catalog): RedirectResponse
    {
        $date = $request->validated('availability_date');
        $catalog->setDailyQuota($request->user(), $product, $date, $request->integer('quota_limit'), $request->validated('reason'));

        return to_route('staff.products.quota.edit', ['product' => $product, 'date' => $date])->with('status', 'Kuota tanggal berhasil diperbarui.');
    }
}
