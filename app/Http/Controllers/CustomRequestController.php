<?php

namespace App\Http\Controllers;

use App\BookingCalendar;
use App\Http\Requests\SubmitCustomRequestRequest;
use App\Models\CustomCakeRequest;
use App\SubmitCustomRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomRequestController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', CustomCakeRequest::class);

        return view('custom-requests.create', ['window' => BookingCalendar::customWindow(CarbonImmutable::now('UTC'), false),
            'idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(SubmitCustomRequestRequest $request, SubmitCustomRequest $submit): JsonResponse|RedirectResponse
    {
        $custom = $submit->create($request->user(), $request->validated());

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $custom->id, 'request_number' => $custom->request_number, 'status' => $custom->status->value]], $custom->wasRecentlyCreated ? 201 : 200)
            : to_route('custom-requests.show', $custom);
    }

    public function show(CustomCakeRequest $customRequest): View
    {
        Gate::authorize('view', $customRequest);

        return view('custom-requests.show', ['custom' => $customRequest->load('attachments')]);
    }
}
