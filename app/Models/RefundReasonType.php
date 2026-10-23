<?php

namespace App\Models;

enum RefundReasonType: string
{
    case CustomerCancel = 'customer_cancel';
    case StoreCancel = 'store_cancel';
    case LatePayment = 'late_payment';
    case AmountAnomaly = 'amount_anomaly';
}
