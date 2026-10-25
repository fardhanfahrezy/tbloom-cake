<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\OpenSnapSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SnapSessionController extends Controller
{
    public function store(Request $request, Order $order, OpenSnapSession $sessions): RedirectResponse
    {
        Gate::authorize('pay', $order);
        try {
            $url = $sessions->open($request->user(), $order);
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw $exception;
            }
            abort(503, 'Pembayaran sedang sibuk. Muat ulang pesanan yang sama; jangan membuat pembayaran baru.');
        }

        return $url === null
            ? to_route('orders.show', $order)->with('status', 'Pembayaran belum dapat dibuka. Jika pembayaran sudah dimulai, jangan membayar ulang; status sedang diperiksa.')
            : redirect()->away($url);
    }
}
