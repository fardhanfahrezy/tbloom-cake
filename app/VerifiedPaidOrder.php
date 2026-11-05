<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use Carbon\CarbonImmutable;

class VerifiedPaidOrder
{
    /** Caller holds the payment lock and has checked the paid state and timestamps. */
    public function allows(Payment $payment, Order $order, CarbonImmutable $now): bool
    {
        if (PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return false;
        }
        $roots = PaymentMovement::query()->where('payment_id', $payment->id)->get();
        if ($roots->count() !== 1) {
            return false;
        }
        $receipt = $roots->first();
        $source = $receipt->sourceEvent;
        if ($receipt->kind !== PaymentMovementKind::Receipt || $receipt->source !== PaymentMovementSource::GatewayReceipt
            || $receipt->canonical_key !== PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id])
            || $receipt->amount !== $order->product_total || $receipt->occurred_at < $order->paid_at || $receipt->occurred_at > $now
            || $receipt->verified_at === null || $receipt->verified_at > $now || $source === null || $source->verified_at === null) {
            return false;
        }
        $events = PaymentEvent::query()->where(fn ($query) => $query->where('payment_id', $payment->id)
            ->orWhere(fn ($reference) => $reference->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->get()->keyBy('id');
        foreach ($events as $event) {
            if ($event->payment_id !== $payment->id || $event->provider !== $payment->provider || $event->provider_reference !== $payment->merchant_reference
                || $event->verified_at === null || $event->verified_at > $now || ! in_array($event->processing_status, [PaymentEventStatus::Processed, PaymentEventStatus::Review], true)
                || ($event->processing_status === PaymentEventStatus::Review && ! in_array($event->error, [PaymentReviewReason::TimeUnverified->value, PaymentReviewReason::TerminalReceipt->value], true))) {
                return false;
            }
        }
        if (! $events->has($source->id) || ($payment->closure_result !== null && ! AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)
            ->where('action', 'payment.timely_recovery_applied')->where('after_redacted->closure_event_id', $payment->closure_event_id)->exists())) {
            return false;
        }
        $audits = AuditLog::query()->where('entity_type', 'payment')->where('entity_id', $payment->id)->where('action', 'payment.time_attested')
            ->where('actor_type', 'user')->whereNotNull('actor_user_id')->where('after_redacted->paid_at', $payment->provider_paid_at->format(DATE_ATOM))->get();
        foreach ($audits as $audit) {
            $eventId = $audit->after_redacted['event_id'] ?? null;
            if (is_int($eventId) && $events->has($eventId)) {
                return true;
            }
        }

        return false;
    }
}
