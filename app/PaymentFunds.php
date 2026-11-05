<?php

namespace App;

use App\Models\Payment;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementKind;
use App\Models\RefundLog;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use Illuminate\Support\Facades\DB;
use LogicException;
use OverflowException;

class PaymentFunds
{
    /**
     * Caller holds payment/refund/attempt parent locks in the global order.
     * Corrections replace a root's interpretation; they are never extra money.
     *
     * @return array{receipts: int, returns: int, reserved: int, available: int, remaining_obligation: int, excess_returns: bool}
     */
    public function snapshot(Payment $payment, ?int $excludingTransferId = null): array
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() === 0) {
            throw new LogicException('Financial decisions require a MySQL parent lock transaction.');
        }
        $receipts = 0;
        $returns = 0;
        foreach (PaymentMovement::query()->where('payment_id', $payment->id)->with('headCorrection')->orderBy('id')->get() as $movement) {
            $amount = $movement->headCorrection?->effective_amount ?? $movement->amount;
            if ($movement->kind === PaymentMovementKind::Receipt) {
                $receipts = $this->add($receipts, $amount);
            } else {
                $returns = $this->add($returns, $amount);
            }
        }
        $reserved = 0;
        foreach (RefundTransfer::query()->where('payment_id', $payment->id)
            ->whereIn('status', [RefundTransferStatus::Prepared, RefundTransferStatus::Unknown])->orderBy('id')->get() as $transfer) {
            if ($transfer->id !== $excludingTransferId) {
                $reserved = $this->add($reserved, $transfer->amount);
            }
        }
        $obligation = RefundLog::query()->where('payment_id', $payment->id)->first();

        return [
            'receipts' => $receipts, 'returns' => $returns, 'reserved' => $reserved,
            'available' => max(0, max(0, $receipts - $returns) - $reserved),
            'remaining_obligation' => max(0, max(0, ($obligation?->amount ?? 0) - $returns) - $reserved),
            'excess_returns' => $returns > $receipts,
        ];
    }

    private function add(int $total, int $amount): int
    {
        if ($amount < 0 || $amount > PHP_INT_MAX - $total) {
            throw new OverflowException('Financial aggregate exceeds the supported integer range.');
        }

        return $total + $amount;
    }
}
