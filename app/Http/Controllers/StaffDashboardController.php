<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CustomCakeRequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryStatus;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentEventStatus;
use App\Models\PickupMethod;
use App\Models\RefundLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffDashboardController extends Controller
{
    public function show(Request $request): View
    {
        $filters = [
            'order_number' => trim((string) $request->query('order_number', '')),
            'status' => trim((string) $request->query('status', '')),
            'created_date' => trim((string) $request->query('created_date', '')),
            'pickup_date' => trim((string) $request->query('pickup_date', '')),
            'pickup_method' => trim((string) $request->query('pickup_method', '')),
            'customer_contact' => trim((string) $request->query('customer_contact', '')),
        ];

        $ordersQuery = Order::query()->with(['item', 'payment', 'refundLog']);

        if ($filters['order_number'] !== '') {
            $escapedOrderNumber = addcslashes($filters['order_number'], '%_\\');
            $ordersQuery->where('order_number', 'like', '%'.$escapedOrderNumber.'%');
        }

        if ($filters['status'] !== '' && ($status = OrderStatus::tryFrom($filters['status'])) !== null) {
            $ordersQuery->where('status', $status);
        }

        if ($filters['created_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['created_date']) === 1) {
            $ordersQuery->whereDate('created_at', $filters['created_date']);
        }

        if ($filters['pickup_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['pickup_date']) === 1) {
            $ordersQuery->whereDate('pickup_date', $filters['pickup_date']);
        }

        if ($filters['pickup_method'] !== '' && ($pickupMethod = PickupMethod::tryFrom($filters['pickup_method'])) !== null) {
            $ordersQuery->where('pickup_method', $pickupMethod);
        }

        if ($filters['customer_contact'] !== '') {
            $contact = $filters['customer_contact'];
            $escapedContact = addcslashes($contact, '%_\\');
            $digits = preg_replace('/\D+/', '', $contact) ?? '';
            $internationalCandidate = str_starts_with($digits, '08')
                ? '+62'.substr($digits, 1)
                : (str_starts_with($digits, '628') ? '+'.$digits : $escapedContact);

            $ordersQuery->where(function (Builder $query) use ($escapedContact, $internationalCandidate): void {
                $query->where('customer_name_snapshot', 'like', '%'.$escapedContact.'%')
                    ->orWhere('email_snapshot', 'like', '%'.$escapedContact.'%')
                    ->orWhere('whatsapp_snapshot', 'like', '%'.$escapedContact.'%')
                    ->orWhere('whatsapp_snapshot', 'like', '%'.$internationalCandidate.'%');
            });
        }

        return view('staff.dashboard', [
            'filters' => $filters,
            'orders' => $ordersQuery->orderByDesc('id')->limit(25)->get(),
            'customRequests' => CustomCakeRequest::query()->with('assignedAdmin')->orderByDesc('id')->limit(15)->get(),
            'orderRequests' => OrderRequest::query()->with(['order', 'user', 'reviewedBy'])->orderByDesc('id')->limit(15)->get(),
            'refundLogs' => RefundLog::query()->with(['order', 'assignedAdmin', 'transfers'])->orderByDesc('id')->limit(15)->get(),
            'paymentExceptions' => Payment::query()->with('order')->whereNotNull('review_reason')->orderByDesc('id')->limit(15)->get(),
            'paymentEventIssues' => PaymentEvent::query()->with('payment.order')->whereIn('processing_status', [PaymentEventStatus::Review, PaymentEventStatus::Failed])->orderByDesc('id')->limit(15)->get(),
            'notificationIssues' => NotificationDelivery::query()->whereIn('status', [NotificationDeliveryStatus::Failed, NotificationDeliveryStatus::Unknown])->orderByDesc('id')->limit(15)->get(),
            'auditLogs' => AuditLog::query()->with('actor')->orderByDesc('id')->limit(20)->get(),
        ]);
    }
}
