<?php

namespace App\Models;

enum PaymentMovementSource: string
{
    case GatewayReceipt = 'gateway_receipt';
    case ManualRefund = 'manual_refund';
    case ProviderRefund = 'provider_refund';
    case Reversal = 'reversal';
}
