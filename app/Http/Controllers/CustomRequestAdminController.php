<?php

namespace App\Http\Controllers;

use App\CustomRequestWorkflow;
use App\Http\Requests\ChangeCustomDesignRequest;
use App\Http\Requests\TransitionCustomRequestRequest;
use App\Models\CustomCakeRequest;
use App\Models\CustomCakeRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomRequestAdminController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CustomCakeRequest::class);
        $data = $request->validate(['status' => ['nullable', Rule::enum(CustomCakeRequestStatus::class)]]);
        $status = $data['status'] ?? null;

        return view('custom-requests.index', ['requests' => CustomCakeRequest::query()->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(25)->withQueryString(), 'status' => $status]);
    }

    public function edit(CustomCakeRequest $customRequest, CustomRequestWorkflow $workflow): View
    {
        Gate::authorize('manage', $customRequest);

        return view('custom-requests.edit', ['custom' => $customRequest, 'version' => $workflow->version($customRequest),
            'transitionKey' => (string) Str::uuid(), 'designKey' => (string) Str::uuid()]);
    }

    public function transition(TransitionCustomRequestRequest $request, CustomCakeRequest $customRequest, CustomRequestWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $custom = $workflow->transition($customRequest->id, $request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['data' => ['id' => $custom->id, 'status' => $custom->status->value]])
            : to_route('custom-requests.show', $custom)->with('status', 'Status request diperbarui.');
    }

    public function change(ChangeCustomDesignRequest $request, CustomCakeRequest $customRequest, CustomRequestWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $custom = $workflow->change($customRequest->id, $request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['data' => ['id' => $custom->id, 'status' => $custom->status->value]])
            : to_route('custom-requests.show', $custom)->with('status', 'Perubahan desain dicatat.');
    }
}
