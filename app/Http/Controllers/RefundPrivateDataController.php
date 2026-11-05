<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRefundBankRequest;
use App\Http\Requests\UploadRefundEvidenceRequest;
use App\Http\Requests\VerifyRefundBankRequest;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\RefundPrivateData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RefundPrivateDataController extends Controller
{
    public function show(Request $request, RefundLog $refund, RefundPrivateData $data): JsonResponse
    {
        Gate::authorize('privateData', $refund);

        return new JsonResponse(['data' => $data->bank($refund->id, $request->user())], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function update(SaveRefundBankRequest $request, RefundLog $refund, RefundPrivateData $data): JsonResponse
    {
        $saved = $data->saveBank($refund->id, $request->user(), $request->validated(), $request->has('assignment_version') ? $request->integer('assignment_version') : null);

        return new JsonResponse(['saved' => $saved], $saved ? 200 : 409, ['Cache-Control' => 'no-store, private']);
    }

    public function verify(VerifyRefundBankRequest $request, RefundLog $refund, RefundPrivateData $data): JsonResponse
    {
        $verified = $data->verifyBank($refund->id, $request->user(), $request->validated());

        return new JsonResponse(['verified' => $verified], $verified ? 200 : 409, ['Cache-Control' => 'no-store, private']);
    }

    public function upload(UploadRefundEvidenceRequest $request, RefundLog $refund, RefundTransfer $transfer, RefundPrivateData $data): JsonResponse
    {
        abort_unless($transfer->refund_log_id === $refund->id, 404);
        $evidence = $data->upload($refund->id, $transfer->id, $request->user(), $request->integer('assignment_version'), $request->file('upload'));

        return new JsonResponse(['data' => $evidence === null ? null : ['id' => $evidence->id]], $evidence === null ? 409 : 201, ['Cache-Control' => 'no-store, private']);
    }

    public function download(Request $request, RefundEvidence $evidence, RefundPrivateData $data): Response
    {
        $file = $data->download($evidence->id, $request->user());

        return new Response($file['bytes'], 200, ['Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
