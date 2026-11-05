<?php

namespace Tests;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMovement;
use App\Models\User;
use App\PaymentMovementKey;
use Illuminate\Support\Str;

class HandoverFixture
{
    public static function paid(): Order
    {
        $order = Order::factory()->paid()->create(['pickup_date' => '2026-11-07', 'product_total' => 100001,
            'created_at' => '2026-11-01 03:00:00', 'paid_at' => '2026-11-01 03:09:59']);
        OrderItem::factory()->for($order)->create(['unit_price' => 100001, 'line_total' => 100001]);
        $payment = Payment::factory()->paid()->for($order)->create(['merchant_reference' => $order->order_number,
            'provider_transaction_id' => (string) Str::uuid(), 'provider_paid_at' => $order->paid_at, 'created_at' => $order->created_at]);
        $event = PaymentEvent::factory()->for($payment)->processed()->create(['verified_at' => now()]);
        PaymentMovement::factory()->create(['payment_id' => $payment->id, 'source_event_id' => $event->id, 'amount' => 100001,
            'canonical_key' => PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id]), 'occurred_at' => '2026-11-01 03:10:00']);
        AuditLog::factory()->create(['actor_type' => 'user', 'actor_user_id' => User::factory()->owner()->create()->id,
            'entity_type' => 'payment', 'entity_id' => $payment->id, 'action' => 'payment.time_attested',
            'after_redacted' => ['event_id' => $event->id, 'paid_at' => '2026-11-01T03:09:59+00:00', 'evidence_sha256' => hash('sha256', 'synthetic-handover-proof')]]);

        return $order->fresh(['item', 'payment']);
    }
}
