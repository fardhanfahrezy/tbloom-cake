<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRequest;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\PaymentMovement;
use App\Models\PaymentMovementCorrection;
use App\Models\PaymentMovementKind;
use App\Models\PaymentMovementSource;
use App\Models\PaymentStatus;
use App\Models\RefundEvidence;
use App\Models\RefundLog;
use App\Models\RefundLogStatus;
use App\Models\RefundReasonType;
use App\Models\RefundTransfer;
use App\Models\RefundTransferStatus;
use App\Models\User;
use App\Models\UserRole;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use OverflowException;

class RefundTransfers
{
    private const UNKNOWN_REVIEW = 'manual_bank_outcome_unknown';

    public function __construct(private PaymentFunds $funds, private RefundPrivateData $privateData, private PaymentNotices $notices) {}

    /** @return array<string, list<string>> */
    public static function prepareRules(): array
    {
        return AssignRefund::versionRules() + ['amount' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/']];
    }

    /** @return array<string, list<string>> */
    public static function resolutionRules(bool $success): array
    {
        return AssignRefund::versionRules() + ['bank_statement_checked' => ['required', 'accepted'], 'evidence_id' => ['required', 'integer', 'min:1'],
            'verification_note' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'independence_confirmed' => ['sometimes', 'accepted'], 'financial_context_token' => ['nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'independence_note' => ['required_with:financial_context_token', 'nullable', 'string', 'max:1000', 'regex:/\S/u']]
            + ($success ? ['transfer_reference' => ['required', 'string', 'max:128', 'regex:/\S/u', 'not_regex:/[\x00-\x1F\x7F]/u'],
                'transferred_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP']] : []);
    }

    /** @param array<string, mixed> $input */
    public function prepare(int $refundId, User $actor, array $input): ?RefundTransfer
    {
        $data = Validator::make($input, self::prepareRules())->validate();

        return $this->locked($refundId, $actor, (int) $data['assignment_version'], function (RefundLog $refund, Payment $payment, Order $order, Collection $transfers, User $current) use ($data): ?RefundTransfer {
            $existing = $transfers->firstWhere('idempotency_key', $data['idempotency_key']);
            if ($existing !== null) {
                return $existing->amount === (int) $data['amount'] && $existing->prepared_by === $current->id
                    && $existing->assignment_version === (int) $data['assignment_version'] ? $existing : null;
            }
            if (! in_array($refund->status, [RefundLogStatus::Pending, RefundLogStatus::Processing], true) || $refund->review_reason !== null
                || ! $this->verifiedBank($refund) || ! $this->financiallyReady($refund, $payment, $order, $transfers, (int) $data['amount'])) {
                return null;
            }
            $number = ($transfers->max('attempt_number') ?? 0) + 1;
            if ($number > 4294967295 || config('refunds.source_account_key') !== 'REK_UTAMA_TBLOOM_BCA'
                || config('refunds.source_bank') !== 'BCA' || config('refunds.source_channel') !== 'bca_mobile') {
                return null;
            }
            $beforeStatus = $refund->status;
            if ($beforeStatus === RefundLogStatus::Pending) {
                $refund->forceFill(['status' => RefundLogStatus::Processing])->save();
            }
            $transfer = new RefundTransfer;
            $transfer->forceFill(['refund_log_id' => $refund->id, 'payment_id' => $payment->id, 'attempt_number' => $number,
                'amount' => (int) $data['amount'], 'status' => RefundTransferStatus::Prepared, 'prepared_by' => $current->id,
                'assignment_version' => $refund->assignment_version, 'idempotency_key' => $data['idempotency_key'],
                'source_account_key' => config('refunds.source_account_key'), 'bank_name' => $refund->bank_name,
                'account_number' => $refund->account_number, 'account_holder' => $refund->account_holder,
                'bank_verified_at' => $refund->bank_verified_at, 'prepared_at' => CarbonImmutable::now('UTC')->startOfSecond()])->save();
            $this->audit($transfer, $current, 'refund.transfer_prepared', ['amount' => $transfer->amount, 'assignment_version' => $refund->assignment_version,
                'snapshot_hmac' => $this->preparedToken($transfer), 'refund_status_before' => $beforeStatus->value,
                'refund_status_after' => $refund->status->value]);

            return $transfer;
        });
    }

    public function markUnknown(int $refundId, int $transferId, User $actor, int $version): ?RefundTransfer
    {
        Validator::make(['assignment_version' => $version], AssignRefund::versionRules())->validate();

        return $this->locked($refundId, $actor, $version, function (RefundLog $refund, Payment $payment, Order $order, Collection $transfers, User $current) use ($transferId): ?RefundTransfer {
            $transfer = $this->attempt($transfers, $transferId);
            if ($transfer->status === RefundTransferStatus::Unknown) {
                return $this->unknownProof($transfer) ? $transfer : null;
            }
            if ($transfer->status !== RefundTransferStatus::Prepared || ! $this->preparedProof($transfer) || $refund->status !== RefundLogStatus::Processing || $refund->review_reason !== null
                || ! $this->financiallyReady($refund, $payment, $order, $transfers, $transfer->amount, $transfer->id)) {
                return null;
            }
            $transfer->forceFill(['status' => RefundTransferStatus::Unknown])->save();
            $refund->forceFill(['status' => RefundLogStatus::Review, 'review_reason' => self::UNKNOWN_REVIEW])->save();
            $this->audit($transfer, $current, 'refund.transfer_unknown', ['amount' => $transfer->amount, 'assignment_version' => $refund->assignment_version]);

            return $transfer;
        });
    }

    /** @param array<string, mixed> $input */
    public function complete(int $refundId, int $transferId, User $actor, array $input): ?RefundTransfer
    {
        return $this->resolve($refundId, $transferId, $actor, $input, true);
    }

    /** @param array<string, mixed> $input */
    public function confirmFailure(int $refundId, int $transferId, User $actor, array $input): ?RefundTransfer
    {
        return $this->resolve($refundId, $transferId, $actor, $input, false);
    }

    /** @return array<string, mixed>|null */
    public function completionContext(int $refundId, int $transferId, User $actor, int $version): ?array
    {
        Validator::make(['assignment_version' => $version], AssignRefund::versionRules())->validate();

        return $this->locked($refundId, $actor, $version, function (RefundLog $refund, Payment $payment, Order $order, Collection $transfers, User $current) use ($transferId): ?array {
            abort_unless($current->role === UserRole::Owner, 403);
            $transfer = $this->attempt($transfers, $transferId);
            if ($transfer->status !== RefundTransferStatus::Unknown || ! $this->unknownProof($transfer)) {
                return null;
            }
            $context = $this->financialContext($refund, $payment, $transfers);

            return ['financial_context_token' => $context['token'], 'movements' => $context['movements'],
                'payment_review_reason' => $payment->review_reason, 'refund_review_reason' => $refund->review_reason,
                'ambiguous_facts' => $this->ambiguousFacts($payment, $transfers)];
        });
    }

    /** @param array<string, mixed> $input */
    private function resolve(int $refundId, int $transferId, User $actor, array $input, bool $success): ?RefundTransfer
    {
        $data = Validator::make($input, self::resolutionRules($success))->validate();
        $data['verification_note'] = trim($data['verification_note']);
        $version = (int) $data['assignment_version'];
        $time = $success ? CarbonImmutable::parse($data['transferred_at'])->utc() : null;
        $digest = hash('sha256', json_encode([$success, (int) $data['evidence_id'], $data['verification_note'],
            $success ? $data['transfer_reference'] : null, $time?->format(DATE_ATOM), isset($data['independence_confirmed']),
            $data['financial_context_token'] ?? null, $data['independence_note'] ?? null], JSON_THROW_ON_ERROR));
        $terminal = $success ? RefundTransferStatus::Succeeded : RefundTransferStatus::FailedConfirmed;
        $preflight = $this->locked($refundId, $actor, $version, function (RefundLog $refund, Payment $payment, Order $order, Collection $transfers) use ($transferId, $data, $terminal): ?array {
            $transfer = $this->attempt($transfers, $transferId);
            if (! in_array($transfer->status, [RefundTransferStatus::Unknown, $terminal], true)) {
                return null;
            }
            $evidence = RefundEvidence::query()->whereKey($data['evidence_id'])->where('refund_log_id', $refund->id)
                ->where('refund_transfer_id', $transfer->id)->lockForUpdate()->first();

            return $evidence === null ? null : ['transfer' => $transfer, 'evidence' => $evidence,
                'context_token' => $this->financialContext($refund, $payment, $transfers)['token']];
        });
        if ($preflight === null) {
            return null;
        }
        $this->privateData->readEvidence($preflight['evidence']);

        return $this->locked($refundId, $actor, $version, function (RefundLog $refund, Payment $payment, Order $order, Collection $transfers, User $current) use ($transferId, $data, $terminal, $digest, $time, $success, $preflight): ?RefundTransfer {
            $transfer = $this->attempt($transfers, $transferId);
            $evidence = RefundEvidence::query()->whereKey($data['evidence_id'])->where('refund_log_id', $refund->id)->where('refund_transfer_id', $transfer->id)->lockForUpdate()->first();
            if ($evidence === null || $evidence->getRawOriginal() !== $preflight['evidence']->getRawOriginal()) {
                return null;
            }
            $action = $success ? 'refund.transfer_succeeded' : 'refund.transfer_failed_confirmed';
            if ($transfer->status === $terminal) {
                return AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', $action)
                    ->where('actor_type', 'user')->where('actor_user_id', $current->id)->where('after_redacted->resolution_sha256', $digest)->exists() ? $transfer : null;
            }
            if ($transfer->status !== RefundTransferStatus::Unknown
                || $transfer->getRawOriginal() !== $preflight['transfer']->getRawOriginal() || ! $this->unknownProof($transfer)) {
                return null;
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($success && ($time < $transfer->prepared_at || $time > $now
                || ! AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_unknown')->where('created_at', '<=', $time)->exists())) {
                return null;
            }
            if ($success && (RefundTransfer::query()->where('source_account_key', $transfer->source_account_key)->where('transfer_reference', $data['transfer_reference'])->whereKeyNot($transfer->id)->exists()
                || PaymentMovement::query()->where('payment_id', $payment->id)
                    ->where('canonical_key', PaymentMovementKey::fromIdentity('manual:bca:return:v1', [$transfer->source_account_key, $data['transfer_reference']]))->exists())) {
                $refund->forceFill(['status' => RefundLogStatus::Review, 'review_reason' => $refund->review_reason === self::UNKNOWN_REVIEW ? PaymentReviewReason::IdentityConflict->value : $refund->review_reason])->save();
                $payment->forceFill(['review_reason' => $payment->review_reason ?? PaymentReviewReason::IdentityConflict->value])->save();
                $referenceHash = hash('sha256', $data['transfer_reference']);
                if (! AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_identity_conflict')->where('after_redacted->reference_sha256', $referenceHash)->exists()) {
                    $this->audit($transfer, $current, 'refund.transfer_identity_conflict', ['reference_sha256' => $referenceHash]);
                    $this->notices->review($order, PaymentReviewReason::IdentityConflict);
                }

                return null;
            }
            $ambiguous = $success && $this->ambiguousFacts($payment, $transfers);
            if ($ambiguous && ($current->role !== UserRole::Owner || ! isset($data['independence_confirmed']) || empty($data['independence_note'])
                || ! isset($data['financial_context_token']) || ! hash_equals($preflight['context_token'], $data['financial_context_token'])
                || ! hash_equals($this->financialContext($refund, $payment, $transfers)['token'], $data['financial_context_token']))) {
                return null;
            }
            $clean = ! $ambiguous && $refund->status !== RefundLogStatus::Completed && in_array($refund->review_reason, [null, self::UNKNOWN_REVIEW], true)
                && $this->financiallyReady($refund, $payment, $order, $transfers, $transfer->amount, $transfer->id);
            $transfer->forceFill(['status' => $terminal, 'verified_by' => $current->id, 'verified_at' => $now, 'verification_note' => $data['verification_note']]
                + ($success ? ['transfer_reference' => $data['transfer_reference'], 'transferred_at' => $time, 'completed_at' => $now] : []))->save();
            if ($success) {
                $movement = new PaymentMovement;
                $movement->forceFill(['payment_id' => $payment->id, 'kind' => PaymentMovementKind::Return, 'source' => PaymentMovementSource::ManualRefund,
                    'canonical_key' => PaymentMovementKey::fromIdentity('manual:bca:return:v1', [$transfer->source_account_key, $transfer->transfer_reference]),
                    'amount' => $transfer->amount, 'occurred_at' => $time, 'verified_at' => $now, 'refund_transfer_id' => $transfer->id,
                    'verified_by' => $current->id, 'reconciliation_note' => $data['verification_note']])->save();
                try {
                    $funds = $this->funds->snapshot($payment);
                    $overflow = false;
                } catch (OverflowException) {
                    $funds = null;
                    $overflow = true;
                }
                $incident = ! $clean || $overflow || $funds['excess_returns'] || $funds['returns'] > $refund->amount;
                $completed = ! $incident && $funds['returns'] >= $refund->amount && $funds['reserved'] === 0;
                $incidentReason = $overflow ? PaymentReviewReason::LedgerOverflow : (($funds['excess_returns'] ?? false) ? PaymentReviewReason::ExcessReturn : PaymentReviewReason::ReturnReview);
                $payment->forceFill(['status' => $overflow ? $payment->status : ($funds['returns'] >= $funds['receipts'] ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded),
                    'review_reason' => $incident ? ($payment->review_reason ?? $incidentReason->value) : null])->save();
                $refund->forceFill(['status' => $incident ? RefundLogStatus::Review : ($completed ? RefundLogStatus::Completed : RefundLogStatus::Processing),
                    'review_reason' => $incident ? (! in_array($refund->review_reason, [null, self::UNKNOWN_REVIEW], true) ? $refund->review_reason : $incidentReason->value) : null,
                    'completed_at' => $completed ? $now : null, 'completed_by' => $completed ? $current->id : null])->save();
                $this->notices->refundRecorded($order, $transfer->id, $completed);
                if ($incident) {
                    $this->audit($transfer, $current, 'refund.actual_success_incident', ['reason' => $incidentReason->value,
                        'independence_note_sha256' => $ambiguous ? hash('sha256', $data['independence_note']) : null,
                        'financial_context_sha256' => $ambiguous ? hash('sha256', $data['financial_context_token']) : null], $ambiguous ? $data['independence_note'] : null);
                    $this->notices->review($order, $incidentReason);
                }
            } elseif ($refund->status === RefundLogStatus::Review && $refund->review_reason === self::UNKNOWN_REVIEW) {
                $refund->forceFill(['status' => RefundLogStatus::Processing, 'review_reason' => null])->save();
            }
            $this->audit($transfer, $current, $action, ['amount' => $transfer->amount, 'assignment_version' => $refund->assignment_version,
                'evidence_id' => $evidence->id, 'bank_statement_checked' => true, 'resolution_sha256' => $digest]);

            return $transfer;
        });
    }

    private function verifiedBank(RefundLog $refund): bool
    {
        return $refund->bank_name !== null && trim($refund->bank_name) !== '' && $refund->account_number !== null && trim($refund->account_number) !== ''
            && $refund->account_holder !== null && trim($refund->account_holder) !== '' && $refund->bank_verified_at !== null
            && $refund->bank_verified_at <= CarbonImmutable::now('UTC') && $refund->bank_verified_by !== null
            && AuditLog::query()->where('entity_type', 'refund_log')->where('entity_id', $refund->id)->where('action', 'refund.bank_verified')
                ->where('actor_type', 'user')->where('actor_user_id', $refund->bank_verified_by)->where('created_at', $refund->bank_verified_at)
                ->where('after_redacted->customer_confirmed', true)->exists();
    }

    /** @param Collection<int, RefundTransfer> $transfers */
    private function financiallyReady(RefundLog $refund, Payment $payment, Order $order, Collection $transfers, int $amount, ?int $excludingId = null): bool
    {
        $now = CarbonImmutable::now('UTC');
        if ($payment->provider !== 'midtrans' || $payment->currency !== 'IDR' || $payment->provider_transaction_id === null
            || $payment->review_reason !== null || ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)
            || ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Expired], true) || $payment->expected_amount !== $order->product_total
            || $order->item === null || $order->item->quota_released_at === null || $order->item->quota_released_at > $now
            || PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return false;
        }
        $roots = PaymentMovement::query()->where('payment_id', $payment->id)->with('sourceEvent')->orderBy('id')->get();
        $receipts = $roots->where('kind', PaymentMovementKind::Receipt);
        if ($receipts->count() !== 1) {
            return false;
        }
        $receipt = $receipts->first();
        if ($receipt->source !== PaymentMovementSource::GatewayReceipt || $receipt->canonical_key !== PaymentMovementKey::fromIdentity('midtrans:bca:receipt:v1', [$payment->provider_transaction_id])
            || $receipt->verified_at === null || $receipt->verified_at > $now || $receipt->occurred_at < $order->created_at || $receipt->occurred_at > $now
            || $receipt->sourceEvent === null || $receipt->sourceEvent->verified_at === null || $receipt->amount !== $refund->basis_amount) {
            return false;
        }
        $events = PaymentEvent::query()->where(fn ($query) => $query->where('payment_id', $payment->id)
            ->orWhere(fn ($reference) => $reference->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->get();
        foreach ($events as $event) {
            if ($event->payment_id !== $payment->id || $event->provider !== $payment->provider || $event->provider_reference !== $payment->merchant_reference
                || $event->verified_at === null || $event->verified_at > $now || ! in_array($event->processing_status, [PaymentEventStatus::Processed, PaymentEventStatus::Review], true)
                || ($event->processing_status === PaymentEventStatus::Review && ! in_array($event->error, [PaymentReviewReason::TimeUnverified->value, PaymentReviewReason::TerminalReceipt->value,
                    PaymentReviewReason::ActualLate->value, PaymentReviewReason::AmountAnomaly->value], true))) {
                return false;
            }
        }
        $percentage = $refund->reason_type === RefundReasonType::CustomerCancel ? 80 : 100;
        $gross = intdiv($refund->basis_amount, 100) * $percentage + intdiv(($refund->basis_amount % 100) * $percentage, 100);
        if ($refund->percentage !== $percentage || $refund->amount !== $gross) {
            return false;
        }
        $cancellation = in_array($refund->reason_type, [RefundReasonType::CustomerCancel, RefundReasonType::StoreCancel], true);
        $origin = AuditLog::query()->where('entity_type', $cancellation ? 'order' : 'payment')->where('entity_id', $cancellation ? $order->id : $payment->id)
            ->where('action', $cancellation ? 'order.paid_cancelled' : 'payment.exception_resolved')->where('after_redacted->refund_log_id', $refund->id)
            ->where('after_redacted->'.($cancellation ? 'gross_obligation' : 'gross_amount'), $refund->amount)
            ->where('after_redacted->'.($cancellation ? 'cause' : 'reason'), $refund->reason_type->value)->exists();
        if (! $origin) {
            return false;
        }
        foreach ($roots->where('kind', PaymentMovementKind::Return) as $return) {
            $transfer = $transfers->firstWhere('id', $return->refund_transfer_id);
            if ($return->source !== PaymentMovementSource::ManualRefund || $transfer === null || $transfer->status !== RefundTransferStatus::Succeeded
                || $transfer->transfer_reference === null || $transfer->amount !== $return->amount || $return->verified_at > $now
                || $return->canonical_key !== PaymentMovementKey::fromIdentity('manual:bca:return:v1', [$transfer->source_account_key, $transfer->transfer_reference])
                || ! AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_succeeded')->exists()) {
                return false;
            }
        }
        try {
            $funds = $this->funds->snapshot($payment, $excludingId);
        } catch (OverflowException) {
            return false;
        }

        return ! $funds['excess_returns'] && $funds['reserved'] === 0 && $amount > 0
            && $amount <= $funds['available'] && $amount <= $funds['remaining_obligation'];
    }

    private function unknownProof(RefundTransfer $transfer): bool
    {
        return $this->preparedProof($transfer) && AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_unknown')
            ->where('after_redacted->amount', $transfer->amount)->where('created_at', '>=', $transfer->prepared_at)->where('created_at', '<=', CarbonImmutable::now('UTC'))->exists();
    }

    private function preparedProof(RefundTransfer $transfer): bool
    {
        foreach ($this->preparedKeys() as $key) {
            if (AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_prepared')
                ->where('after_redacted->snapshot_hmac', $this->preparedToken($transfer, $key))->where('after_redacted->amount', $transfer->amount)
                ->where('after_redacted->assignment_version', $transfer->assignment_version)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function preparedToken(RefundTransfer $transfer, ?string $key = null): string
    {
        return hash_hmac('sha256', json_encode([$transfer->id, $transfer->refund_log_id, $transfer->payment_id, $transfer->attempt_number, $transfer->amount,
            $transfer->prepared_by, $transfer->assignment_version, $transfer->idempotency_key, $transfer->source_account_key,
            $transfer->bank_name, $transfer->account_number, $transfer->account_holder, $transfer->bank_verified_at->format(DATE_ATOM),
            $transfer->prepared_at->format(DATE_ATOM)], JSON_THROW_ON_ERROR), $key ?? $this->preparedKeys()[0]);
    }

    /** @return list<string> */
    private function preparedKeys(): array
    {
        $previous = config('app.previous_keys', []);
        if (! is_array($previous)) {
            throw new LogicException('Refund proof key history must be a configured key list.');
        }
        $keys = [config('app.key'), ...array_values($previous)];
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                throw new LogicException('Refund proof keys must be valid encryption keys.');
            }
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            if ($raw === false || ! Encrypter::supported($raw, config('app.cipher'))) {
                throw new LogicException('Refund proof keys must match the configured cipher.');
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param Collection<int, RefundTransfer> $transfers */
    private function ambiguousFacts(Payment $payment, Collection $transfers): bool
    {
        if (PaymentMovementCorrection::query()->whereIn('payment_movement_id', PaymentMovement::query()->select('id')->where('payment_id', $payment->id))->exists()) {
            return true;
        }
        foreach (PaymentMovement::query()->where('payment_id', $payment->id)->where('kind', PaymentMovementKind::Return)->get() as $return) {
            $transfer = $transfers->firstWhere('id', $return->refund_transfer_id);
            if ($return->source !== PaymentMovementSource::ManualRefund || $transfer === null || $transfer->status !== RefundTransferStatus::Succeeded
                || $transfer->transfer_reference === null || $return->amount !== $transfer->amount
                || $return->canonical_key !== PaymentMovementKey::fromIdentity('manual:bca:return:v1', [$transfer->source_account_key, $transfer->transfer_reference])
                || ! AuditLog::query()->where('entity_type', 'refund_transfer')->where('entity_id', $transfer->id)->where('action', 'refund.transfer_succeeded')->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param Collection<int, RefundTransfer> $transfers
     * @return array{token: string, movements: list<array<string, mixed>>}
     */
    private function financialContext(RefundLog $refund, Payment $payment, Collection $transfers): array
    {
        $roots = PaymentMovement::query()->where('payment_id', $payment->id)->with('headCorrection')->orderBy('id')->get();
        $movements = $roots->map(fn (PaymentMovement $root): array => ['id' => $root->id, 'kind' => $root->kind->value, 'source' => $root->source->value,
            'canonical_key' => $root->canonical_key, 'amount' => $root->headCorrection?->effective_amount ?? $root->amount,
            'occurred_at' => ($root->headCorrection?->effective_occurred_at ?? $root->occurred_at)->format(DATE_ATOM), 'correction_id' => $root->headCorrection?->id])->all();
        $events = PaymentEvent::query()->where(fn ($query) => $query->where('payment_id', $payment->id)
            ->orWhere(fn ($reference) => $reference->where('provider', $payment->provider)->where('provider_reference', $payment->merchant_reference)))->orderBy('id')->get();
        $token = hash_hmac('sha256', json_encode([$refund->getRawOriginal(), $payment->getRawOriginal(), $movements,
            $transfers->map->getRawOriginal()->all(), $events->map->getRawOriginal()->all()], JSON_THROW_ON_ERROR), config('app.key'));

        return ['token' => $token, 'movements' => $movements];
    }

    /** @param Collection<int, RefundTransfer> $transfers */
    private function attempt(Collection $transfers, int $id): RefundTransfer
    {
        $transfer = $transfers->firstWhere('id', $id);
        abort_if($transfer === null, 404);

        return $transfer;
    }

    /** @param Closure(RefundLog, Payment, Order, Collection<int, RefundTransfer>, User): mixed $operation */
    private function locked(int $refundId, User $actor, int $version, Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Refund transfers require MySQL and no caller transaction.');
        }
        $settings = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level, @@session.time_zone AS time_zone');
        if ($settings->isolation_level !== 'READ-COMMITTED' || $settings->time_zone !== '+00:00') {
            throw new LogicException('Refund transfers require READ COMMITTED and UTC.');
        }
        $snapshot = RefundLog::query()->with('order')->findOrFail($refundId);
        for ($retry = 1; ; $retry++) {
            try {
                return $connection->transaction(function () use ($snapshot, $actor, $version, $operation): mixed {
                    $users = User::query()->whereIn('id', array_filter([$actor->id, $snapshot->order->user_id, $snapshot->assigned_admin_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $order = Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
                    $payment = Payment::query()->whereKey($snapshot->payment_id)->lockForUpdate()->firstOrFail();
                    OrderRequest::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                    $refund = RefundLog::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                    $transfers = RefundTransfer::query()->where('refund_log_id', $refund->id)->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
                    $item = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->first();
                    $order->setRelation('item', $item);
                    $refund->setRelation('order', $order);
                    $current = $users->get($actor->id);
                    abort_unless((int) Auth::id() === $actor->id && $current !== null && $current->is_active && in_array($current->role, [UserRole::Admin, UserRole::Owner], true), 403);
                    Gate::forUser($current)->authorize('privateData', $refund);
                    $clock = CarbonImmutable::now('Asia/Jakarta')->format('H:i:s');
                    abort_unless($clock >= '09:00:00' && $clock <= '17:00:00', 403);
                    abort_unless($refund->assigned_admin_id === $current->id && $refund->assignment_version === $version
                        && $refund->order_id === $order->id && $refund->payment_id === $payment->id && $payment->order_id === $order->id
                        && $order->user_id === $snapshot->order->user_id, 409);

                    return $operation($refund, $payment, $order, $transfers, $current);
                });
            } catch (QueryException $exception) {
                $code = (int) ($exception->errorInfo[1] ?? 0);
                if ($code === 1062) {
                    if ($retry >= 3) {
                        return null;
                    }

                    continue;
                }
                if ($retry >= 3 || ! in_array($code, [1205, 1213], true)) {
                    throw $exception;
                }
                Sleep::for(random_int(20, 60) * $retry)->milliseconds();
            }
        }
    }

    /** @param array<string, mixed> $details */
    private function audit(RefundTransfer $transfer, User $actor, string $action, array $details, ?string $reason = null): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_type' => 'user', 'actor_user_id' => $actor->id, 'entity_type' => 'refund_transfer', 'entity_id' => $transfer->id,
            'action' => $action, 'reason' => $reason, 'after_redacted' => ['status' => $transfer->status->value] + $details, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
