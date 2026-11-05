<?php

namespace App\Http\Controllers;

use App\MidtransSnapSession;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function show(Order $order, MidtransSnapSession $sessions): View
    {
        Gate::authorize('view', $order);

        return view('orders.show', ['order' => $order->load(['item', 'payment']), 'instant' => CarbonImmutable::now('UTC'), 'paymentAvailable' => $sessions->configured()]);
    }
}
