<?php

namespace App\Http\Controllers;

use App\CancellationRequests;
use App\Http\Requests\RejectCancellationRequest;
use App\Http\Requests\SubmitCancellationRequest;
use App\Models\Order;
use App\Models\OrderRequest;
use Illuminate\Http\JsonResponse;

class CancellationRequestController extends Controller
{
    public function store(SubmitCancellationRequest $request, Order $order, CancellationRequests $cancellations): JsonResponse
    {
        $result = $cancellations->submit($order->id, $request->user(), $request->validated());

        return $this->response($result, $result->wasRecentlyCreated ? 201 : 200);
    }

    public function reject(RejectCancellationRequest $request, OrderRequest $orderRequest, CancellationRequests $cancellations): JsonResponse
    {
        return $this->response($cancellations->reject($orderRequest->id, $request->user(), $request->validated('reason')));
    }

    private function response(OrderRequest $request, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => $request->id, 'order_id' => $request->order_id,
            'status' => $request->status->value, 'decision_mode' => $request->decision_mode->value, 'submitted_at' => $request->submitted_at->toIso8601String()]], $status);
    }
}
