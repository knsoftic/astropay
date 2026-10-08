<?php

use App\Enums\AstroPay\Currency;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\Admin\AdminUtrController;
use App\Http\Controllers\Admin\AdminWebhookLogController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1');
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmPasswordController::class, 'store'])->middleware('throttle:10,1');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('deposits/create', [DepositController::class, 'create'])->name('deposits.create');
    Route::post('deposits', [DepositController::class, 'store'])->middleware('throttle:astropay-orders')->name('deposits.store');

    Route::get('withdrawals/create', [WithdrawalController::class, 'create'])->name('withdrawals.create');
    Route::post('withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:astropay-orders')->name('withdrawals.store');

    Route::controller(TransactionController::class)->prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{transaction}', 'show')->name('show');
        Route::post('{transaction}/check', 'check')->middleware('throttle:30,1')->name('check');
        Route::get('{transaction}/pay', 'pay')->name('pay');
        Route::post('{transaction}/utr', 'submitUtr')->middleware('throttle:10,1')->name('utr');
    });
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('balance/{currency}', [AdminDashboardController::class, 'balance'])->middleware('throttle:30,1')->name('balance');

    Route::controller(AdminTransactionController::class)->prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{transaction}', 'show')->name('show');
        Route::post('{transaction}/check', 'check')->middleware('throttle:60,1')->name('check');
        Route::post('{transaction}/approve', 'approve')->name('approve');
        Route::post('{transaction}/reject', 'reject')->name('reject');
        Route::post('{transaction}/resolve', 'resolve')->name('resolve');
    });

    Route::get('utr', [AdminUtrController::class, 'index'])->name('utr.index');
    Route::post('utr/query', [AdminUtrController::class, 'query'])->middleware('throttle:30,1')->name('utr.query');
    Route::post('utr/supplement', [AdminUtrController::class, 'supplement'])->middleware('throttle:30,1')->name('utr.supplement');

    Route::get('webhooks', [AdminWebhookLogController::class, 'index'])->name('webhooks.index');

    // Merchant credentials: password re-confirmation required (valid for 15 minutes).
    Route::middleware(RequirePassword::using('password.confirm', 900))
        ->controller(AdminSettingsController::class)
        ->prefix('settings')
        ->name('settings.')
        ->group(function () {
            Route::get('/', 'edit')->name('edit');
            Route::put('connection', 'updateGeneral')->name('general');
            Route::put('accounts/{currency}', 'updateAccount')->whereIn('currency', Currency::values())->name('account');
            Route::post('accounts/{currency}/test', 'testAccount')->whereIn('currency', Currency::values())->middleware('throttle:20,1')->name('test');
        });
});
