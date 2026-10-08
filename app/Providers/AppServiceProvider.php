<?php

namespace App\Providers;

use App\Enums\AstroPay\TransactionStatus;
use App\Models\AstroPayTransaction;
use App\Models\User;
use App\Services\AstroPay\AstroPayManager;
use App\Services\AstroPay\AstroPaySettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AstroPaySettings::class);
        $this->app->singleton(AstroPayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Deposit/withdrawal creation per user.
        RateLimiter::for('astropay-orders', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->getKey() ?? $request->ip()));
        });

        // Callback endpoint per source IP.
        RateLimiter::for('astropay-webhooks', function (Request $request) {
            return Limit::perMinute(600)->by((string) $request->ip());
        });

        // Badge counts in the admin sidebar.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            if (! $user instanceof User || ! $user->isAdmin()) {
                return;
            }

            $view->with('navCounts', [
                'approvals' => AstroPayTransaction::query()->where('status', TransactionStatus::AwaitingApproval->value)->count(),
                'reviews' => AstroPayTransaction::query()->where('needs_review', true)->count(),
            ]);
        });
    }
}
