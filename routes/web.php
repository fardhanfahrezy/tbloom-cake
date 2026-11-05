<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CancellationRequestController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomRequestAdminController;
use App\Http\Controllers\CustomRequestAttachmentController;
use App\Http\Controllers\CustomRequestController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\NewPasswordController;
use App\Http\Controllers\OrderChangeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaidCancellationController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\PaymentReconciliationController;
use App\Http\Controllers\ProductAdminController;
use App\Http\Controllers\ProductImageAdminController;
use App\Http\Controllers\ProductQuotaAdminController;
use App\Http\Controllers\ProductVariantAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RefundAssignmentController;
use App\Http\Controllers\RefundPrivateDataController;
use App\Http\Controllers\RefundTransferController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SnapSessionController;
use App\Http\Controllers\StaffAccountController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('/pickup', 'pickup')->name('pickup.show');
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{product:slug}', [CatalogController::class, 'show'])->name('catalog.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-recovery')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-recovery')->name('password.update');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:registration')->name('register.store');
});

Route::middleware(['auth', EnsureActiveAccount::class, 'auth.session'])->group(function (): void {
    Route::get('/staff/orders/{order}/handover', [HandoverController::class, 'show'])->name('staff.orders.handover.show');
    Route::post('/staff/orders/{order}/handover', [HandoverController::class, 'store'])->middleware('throttle:10,1')->name('staff.orders.handover.store');
    Route::get('/custom-requests/create', [CustomRequestController::class, 'create'])->name('custom-requests.create');
    Route::post('/custom-requests', [CustomRequestController::class, 'store'])->middleware('throttle:6,1')->name('custom-requests.store');
    Route::get('/custom-requests/{customRequest}', [CustomRequestController::class, 'show'])->name('custom-requests.show');
    Route::get('/custom-attachments/{attachment}', [CustomRequestAttachmentController::class, 'show'])->middleware('throttle:30,1')->name('custom-attachments.show');
    Route::middleware(EnsureRole::class.':admin,owner')->prefix('/staff/custom-requests')->name('staff.custom-requests.')->group(function (): void {
        Route::get('/', [CustomRequestAdminController::class, 'index'])->name('index');
        Route::get('/{customRequest}/edit', [CustomRequestAdminController::class, 'edit'])->name('edit');
        Route::post('/{customRequest}/status', [CustomRequestAdminController::class, 'transition'])->middleware('throttle:10,1')->name('status');
        Route::patch('/{customRequest}/design', [CustomRequestAdminController::class, 'change'])->middleware('throttle:10,1')->name('design');
    });
    Route::middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->prefix('/staff/refunds/{refund}/transfers')->name('staff.refunds.transfers.')->group(function (): void {
        Route::post('/', [RefundTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}/completion-context', [RefundTransferController::class, 'context'])->name('completion-context.show');
        Route::post('/{transfer}/unknown', [RefundTransferController::class, 'unknown'])->name('unknown.store');
        Route::post('/{transfer}/completion', [RefundTransferController::class, 'complete'])->name('completion.store');
        Route::post('/{transfer}/confirmed-failure', [RefundTransferController::class, 'failure'])->name('confirmed-failure.store');
    });
    Route::get('/refunds/{refund}/bank-details', [RefundPrivateDataController::class, 'show'])->name('refunds.bank.show');
    Route::put('/refunds/{refund}/bank-details', [RefundPrivateDataController::class, 'update'])->middleware('throttle:10,1')->name('refunds.bank.update');
    Route::post('/staff/refunds/{refund}/bank-verification', [RefundPrivateDataController::class, 'verify'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.refunds.bank-verification.store');
    Route::post('/staff/refunds/{refund}/transfers/{transfer}/evidence', [RefundPrivateDataController::class, 'upload'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.refunds.evidence.store');
    Route::get('/refund-evidence/{evidence}', [RefundPrivateDataController::class, 'download'])->middleware('throttle:30,1')->name('refund-evidence.show');
    Route::get('/checkout/{product:slug}', [CheckoutController::class, 'create'])->name('checkout.create');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/cancellation-requests', [CancellationRequestController::class, 'store'])->middleware('throttle:10,1')->name('orders.cancellation-requests.store');
    Route::post('/orders/{order}/change-requests', [OrderChangeController::class, 'store'])->middleware('throttle:10,1')->name('orders.change-requests.store');
    Route::patch('/orders/{order}/handover-note', [OrderChangeController::class, 'updateNote'])->middleware('throttle:10,1')->name('orders.handover-note.update');
    Route::post('/staff/change-requests/{orderRequest}/approval', [OrderChangeController::class, 'approve'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.change-requests.approval.store');
    Route::post('/staff/change-requests/{orderRequest}/rejection', [OrderChangeController::class, 'reject'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.change-requests.rejection.store');
    Route::post('/staff/cancellation-requests/{orderRequest}/rejection', [CancellationRequestController::class, 'reject'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.cancellation-requests.rejection.store');
    Route::post('/staff/cancellation-requests/{orderRequest}/approval', [PaidCancellationController::class, 'approve'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.cancellation-requests.approval.store');
    Route::post('/staff/orders/{order}/cancellation', [PaidCancellationController::class, 'store'])
        ->middleware([EnsureRole::class.':admin,owner', 'throttle:10,1'])->name('staff.orders.cancellation.store');
    Route::post('/staff/refunds/{refund}/claim', [RefundAssignmentController::class, 'claim'])
        ->middleware([EnsureRole::class.':admin', 'throttle:10,1'])->name('staff.refunds.claim.store');
    Route::post('/staff/refunds/{refund}/takeover', [RefundAssignmentController::class, 'takeOver'])
        ->middleware([EnsureRole::class.':owner', 'throttle:10,1'])->name('staff.refunds.takeover.store');
    Route::post('/orders/{order}/payment-session', [SnapSessionController::class, 'store'])->middleware('throttle:6,1')->name('orders.payment-session.store');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
    Route::middleware(EnsureRole::class.':admin,owner')->prefix('staff')->name('staff.')->group(function (): void {
        Route::get('products/{product}/quota', [ProductQuotaAdminController::class, 'edit'])->name('products.quota.edit');
        Route::patch('products/{product}/default-quota', [ProductQuotaAdminController::class, 'updateDefault'])->name('products.quota.default');
        Route::put('products/{product}/availability', [ProductQuotaAdminController::class, 'updateDaily'])->name('products.quota.daily');
        Route::resource('products', ProductAdminController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::resource('products.images', ProductImageAdminController::class)->only(['create', 'store', 'edit', 'update', 'destroy'])->scoped();
        Route::resource('products.variants', ProductVariantAdminController::class)->only(['create', 'store', 'edit', 'update'])->scoped();
    });
    Route::view('/staff', 'staff.dashboard')->middleware(EnsureRole::class.':admin,owner')->name('staff.dashboard');
    Route::post('/staff/payments/{payment}/reconciliation', [PaymentReconciliationController::class, 'store'])
        ->middleware([EnsureRole::class.':owner', 'throttle:6,1'])->name('staff.payments.reconciliation.store');
    Route::middleware(EnsureRole::class.':owner')->prefix('staff/accounts')->name('staff.accounts.')->group(function (): void {
        Route::get('/', [StaffAccountController::class, 'index'])->name('index');
        Route::post('/', [StaffAccountController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::patch('/{user}', [StaffAccountController::class, 'update'])->middleware('throttle:10,1')->name('update');
    });
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
