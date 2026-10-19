<?php

namespace App\Providers;

use App\Models\Shift;
use App\Models\User;
use App\Services\AuthenticationAuditService;
use App\Services\DiscountService;
use App\Services\ShiftAccessService;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DiscountService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ShiftAccessService $shiftAccess): void
    {
        Event::listen([Login::class, Failed::class, Logout::class, CurrentDeviceLogout::class], AuthenticationAuditService::class);

        Gate::define('review-financial', fn (User $actor): bool => $actor->hasAnyRole(['admin', 'owner']) && $actor->can('view_all_shifts') && $actor->can('manage_shifts'));
        Gate::define('manage-accounting-setup', fn (User $actor): bool => $actor->hasRole('owner') && $actor->can('manage_users'));
        Gate::define('operate-shift', fn (User $actor, Shift $shift): bool => $shiftAccess->canOperate($actor, $shift));
        Gate::define('view-shift', fn (User $actor, Shift $shift): bool => $actor->can('manage_shifts') && ($shift->isOpen()
            ? $shiftAccess->canOperate($actor, $shift) || ($actor->hasAnyRole(['admin', 'owner']) && $actor->can('view_all_shifts'))
            : $shiftAccess->visibleShifts($actor)->whereKey($shift->id)->exists()));
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
