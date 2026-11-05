<?php

namespace App\Http\Controllers;

use App\CancelPaidOrder;
use App\Http\Requests\DecidePaidCancellationRequest;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\RefundLog;
use Illuminate\Http\JsonResponse;

class PaidCancellationController extends Controller
{
    public function approve(DecidePaidCancellationRequest $request, OrderRequest $orderRequest, CancelPaidOrder $cancellations): JsonResponse
    {
        return $this->response($cancellations->approve($orderRequest->id, $request->user(), $request->validated('reason')));
    }

    public function store(DecidePaidCancellationRequest $request, Order $order, CancelPaidOrder $cancellations): JsonResponse
    {
        return $this->response($cancellations->byStore($order->id, $request->user(), $request->validated('reason')));
    }

    private function response(?RefundLog $refund): JsonResponse
    {
        return new JsonResponse(['data' => $refund === null ? null : ['id' => $refund->id, 'order_id' => $refund->order_id,
            'reason_type' => $refund->reason_type->value, 'basis_amount' => $refund->basis_amount, 'percentage' => $refund->percentage,
            'amount' => $refund->amount, 'status' => $refund->status->value]], $refund === null ? 409 : 200);
    }
}
