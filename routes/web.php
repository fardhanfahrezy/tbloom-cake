<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\NewPasswordController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\ProductAdminController;
use App\Http\Controllers\ProductImageAdminController;
use App\Http\Controllers\ProductVariantAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\StaffAccountController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
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
    Route::middleware(EnsureRole::class.':admin,owner')->prefix('staff')->name('staff.')->group(function (): void {
        Route::resource('products', ProductAdminController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::resource('products.images', ProductImageAdminController::class)->only(['create', 'store', 'edit', 'update', 'destroy'])->scoped();
        Route::resource('products.variants', ProductVariantAdminController::class)->only(['create', 'store', 'edit', 'update'])->scoped();
    });
    Route::view('/staff', 'staff.dashboard')->middleware(EnsureRole::class.':admin,owner')->name('staff.dashboard');
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
