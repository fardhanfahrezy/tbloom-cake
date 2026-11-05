<?php

namespace App\Http\Controllers;

use App\CompleteHandover;
use App\Http\Requests\CompleteHandoverRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HandoverController extends Controller
{
    public function show(Order $order, CompleteHandover $handover): View
    {
        Gate::authorize('handover', $order);

        $order->load(['item', 'payment', 'requests']);

        return view('orders.handover', ['order' => $order, 'version' => $handover->version($order), 'idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(CompleteHandoverRequest $request, Order $order, CompleteHandover $handover): JsonResponse|RedirectResponse
    {
        $completed = $handover->complete($order->id, $request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['data' => ['id' => $completed->id, 'status' => $completed->status->value,
            'completed_at' => $completed->completed_at->toIso8601String()]])
            : to_route('orders.show', $completed)->with('status', 'Penyerahan pesanan sudah dicatat.');
    }
}
