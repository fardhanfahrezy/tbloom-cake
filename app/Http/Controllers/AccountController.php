<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account', [
            'user' => $user,
            'orders' => $user->orders()
                ->with(['item', 'payment', 'refundLog'])
                ->orderByDesc('id')
                ->get(),
            'customRequests' => $user->customCakeRequests()
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
