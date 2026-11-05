<?php

namespace App\Http\Controllers;

use App\AssignRefund;
use App\Http\Requests\ClaimRefundRequest;
use App\Http\Requests\TakeOverRefundRequest;
use App\Models\RefundLog;
use Illuminate\Http\JsonResponse;

class RefundAssignmentController extends Controller
{
    public function claim(ClaimRefundRequest $request, RefundLog $refund, AssignRefund $assignments): JsonResponse
    {
        return $this->response($assignments->claim($refund->id, $request->user(), $request->integer('assignment_version')));
    }

    public function takeOver(TakeOverRefundRequest $request, RefundLog $refund, AssignRefund $assignments): JsonResponse
    {
        return $this->response($assignments->takeOver($refund->id, $request->user(), $request->integer('assigned_admin_id'),
            $request->integer('assignment_version'), $request->validated('reason')));
    }

    private function response(?RefundLog $refund): JsonResponse
    {
        return new JsonResponse(['data' => $refund === null ? null : ['id' => $refund->id, 'assigned_admin_id' => $refund->assigned_admin_id,
            'assignment_version' => $refund->assignment_version, 'status' => $refund->status->value]], $refund === null ? 409 : 200);
    }
}
