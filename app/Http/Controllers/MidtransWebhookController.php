<?php

namespace App\Http\Controllers;

use App\Models\PaymentEventStatus;
use App\ProcessPaymentEventJob;
use App\ReceiveMidtransNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveMidtransNotification $inbox): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'JSON notification required.');
        $event = $inbox->receive($request->getContent());
        if ($event->processing_status === PaymentEventStatus::Pending && ! $event->next_attempt_at?->isFuture()) {
            try {
                ProcessPaymentEventJob::dispatch($event->id);
            } catch (Throwable) {
                Log::warning('Payment inbox enqueue failed.', ['event_id' => $event->id]);
            }
        }

        return new JsonResponse(['received' => true]);
    }
}
