<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideOrderChangeRequest;
use App\Http\Requests\SaveHandoverNoteRequest;
use App\Http\Requests\SubmitOrderChangeRequest;
use App\Models\Order;
use App\Models\OrderRequest;
use App\OrderChanges;
use Illuminate\Http\JsonResponse;

class OrderChangeController extends Controller
{
    public function store(SubmitOrderChangeRequest $request, Order $order, OrderChanges $changes): JsonResponse
    {
        $result = $changes->submit($order->id, $request->user(), $request->validated());

        return $this->response($result, $result->wasRecentlyCreated ? 201 : 200);
    }

    public function approve(DecideOrderChangeRequest $request, OrderRequest $orderRequest, OrderChanges $changes): JsonResponse
    {
        return $this->response($changes->approve($orderRequest->id, $request->user(), $request->validated('reason')));
    }

    public function reject(DecideOrderChangeRequest $request, OrderRequest $orderRequest, OrderChanges $changes): JsonResponse
    {
        return $this->response($changes->reject($orderRequest->id, $request->user(), $request->validated('reason')));
    }

    public function updateNote(SaveHandoverNoteRequest $request, Order $order, OrderChanges $changes): JsonResponse
    {
        $result = $changes->updateNote($order->id, $request->user(), $request->validated('handover_note'));

        return new JsonResponse(['data' => ['id' => $result->id, 'handover_note' => $result->handover_note]]);
    }

    private function response(OrderRequest $request, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => ['id' => $request->id, 'order_id' => $request->order_id, 'status' => $request->status->value]], $status);
    }
}
