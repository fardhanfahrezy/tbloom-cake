<?php

namespace App;

enum PaymentReviewReason: string
{
    case ContextConflict = 'provider_context_conflict';
    case TimeUnverified = 'payment_time_unverified';
    case FundsTimeUnverified = 'funds_time_unverified';
    case IdentityConflict = 'ledger_identity_conflict';
    case CorrectionReview = 'ledger_correction_review';
    case AmountAnomaly = 'amount_anomaly';
    case ActualLate = 'actual_late_payment';
    case EvidenceUnverified = 'provider_evidence_unverified';
    case TerminalReceipt = 'terminal_receipt_review';
    case LedgerOverflow = 'ledger_overflow';
    case ExcessReturn = 'excess_return';
    case ReturnReview = 'return_requires_review';
    case ReconciliationConflict = 'reconciliation_conflict';
    case AllocationConflict = 'allocation_inconsistent';
    case ExistingReview = 'existing_payment_review';
    case ReconciliationExhausted = 'reconciliation_exhausted';
    case CapacityReassigned = 'capacity_reassigned';
}
