<?php

namespace App\Http\Controllers;

use App\CloseUnpaidPayment;
use App\Http\Requests\ReconcilePaymentRequest;
use App\Models\Payment;
use App\ReconciledPaymentTime;
use App\ReconciledUnpaidClosure;
use App\ReconcilePayments;
use App\RecoverTimelyPayment;
use App\ResolvePaymentException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class PaymentReconciliationController extends Controller
{
    public function store(ReconcilePaymentRequest $request, Payment $payment, ReconcilePayments $reconciler, CloseUnpaidPayment $closure, ResolvePaymentException $exceptions, RecoverTimelyPayment $recovery): JsonResponse
    {
        $data = $request->validated();
        $processed = match ($data['action']) {
            'confirm_unpaid' => $closure->closeReconciled($payment->id, $request->user(), ReconciledUnpaidClosure::fromFinalReconciliation(
                $data['transaction_id'], CarbonImmutable::parse($data['confirmed_at_utc'], 'UTC'), $data['evidence_reference'],
            )),
            'resolve_exception' => $exceptions->resolve($payment->id, $request->user(), $data['evidence_reference']),
            'recover_timely' => $recovery->recover($payment->id, $request->user(), $data['evidence_reference']),
            'observe' => $reconciler->manually($payment->id, $request->user(), isset($data['paid_at_utc']) ? ReconciledPaymentTime::fromReconciliation(
                $data['transaction_id'], CarbonImmutable::parse($data['paid_at_utc'], 'UTC'), $data['evidence_reference'],
            ) : null),
        };

        return new JsonResponse(['processed' => $processed], $processed ? 200 : 409);
    }
}
