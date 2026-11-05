<?php

namespace App\Http\Controllers;

use App\CatalogQuery;
use App\CreateOrder;
use App\Http\Requests\CatalogBrowseRequest;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function create(CatalogBrowseRequest $request, Product $product, CatalogQuery $catalog): View
    {
        Gate::authorize('create', Order::class);
        $current = $catalog->forDate($request->pickupDate())->whereKey($product->id)->firstOrFail();

        return view('checkout.create', ['card' => $catalog->present($current), 'date' => $request->pickupDate(),
            'window' => $request->window(), 'customer' => $request->user(), 'idempotencyKey' => (string) Str::uuid(), 'instant' => CarbonImmutable::now('UTC')]);
    }

    public function store(CheckoutRequest $request, CreateOrder $checkout): JsonResponse|RedirectResponse
    {
        try {
            $order = $checkout->create($request->user(), $request->validated());
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw $exception;
            }
            abort(503, 'Checkout sedang sibuk. Coba lagi dengan detail pesanan yang sama.');
        }

        if (! $request->expectsJson()) {
            return to_route('orders.show', $order);
        }

        return response()->json(['data' => ['id' => $order->id, 'order_number' => $order->order_number,
            'status' => $order->status->value, 'product_total' => $order->product_total,
            'payment_expires_at' => $order->payment_expires_at->toIso8601String()]], $order->wasRecentlyCreated ? 201 : 200);
    }
}
