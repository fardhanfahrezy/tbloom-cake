<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperateRefundTransferRequest;
use App\Http\Requests\PrepareRefundTransferRequest;
use App\Http\Requests\ResolveRefundTransferRequest;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\RefundTransfers;
use Illuminate\Http\JsonResponse;

class RefundTransferController extends Controller
{
    public function context(OperateRefundTransferRequest $request, RefundLog $refund, RefundTransfer $transfer, RefundTransfers $transfers): JsonResponse
    {
        abort_unless($transfer->refund_log_id === $refund->id, 404);
        $context = $transfers->completionContext($refund->id, $transfer->id, $request->user(), $request->integer('assignment_version'));

        return new JsonResponse(['data' => $context], $context === null ? 409 : 200, ['Cache-Control' => 'no-store, private']);
    }

    public function store(PrepareRefundTransferRequest $request, RefundLog $refund, RefundTransfers $transfers): JsonResponse
    {
        return $this->response($transfers->prepare($refund->id, $request->user(), $request->validated()), true);
    }

    public function unknown(OperateRefundTransferRequest $request, RefundLog $refund, RefundTransfer $transfer, RefundTransfers $transfers): JsonResponse
    {
        abort_unless($transfer->refund_log_id === $refund->id, 404);

        return $this->response($transfers->markUnknown($refund->id, $transfer->id, $request->user(), $request->integer('assignment_version')));
    }

    public function complete(ResolveRefundTransferRequest $request, RefundLog $refund, RefundTransfer $transfer, RefundTransfers $transfers): JsonResponse
    {
        abort_unless($transfer->refund_log_id === $refund->id, 404);

        return $this->response($transfers->complete($refund->id, $transfer->id, $request->user(), $request->validated()));
    }

    public function failure(ResolveRefundTransferRequest $request, RefundLog $refund, RefundTransfer $transfer, RefundTransfers $transfers): JsonResponse
    {
        abort_unless($transfer->refund_log_id === $refund->id, 404);

        return $this->response($transfers->confirmFailure($refund->id, $transfer->id, $request->user(), $request->validated()));
    }

    private function response(?RefundTransfer $transfer, bool $created = false): JsonResponse
    {
        return new JsonResponse(['data' => $transfer === null ? null : ['id' => $transfer->id, 'status' => $transfer->status->value,
            'amount' => $transfer->amount, 'attempt_number' => $transfer->attempt_number]], $transfer === null ? 409 : ($created ? 201 : 200), ['Cache-Control' => 'no-store, private']);
    }
}
