<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticationAuditService
{
    public const EVENTS = ['auth.login', 'auth.failed', 'auth.logout'];

    public function __construct(private Request $request) {}

    public function handle(Login|Failed|Logout|CurrentDeviceLogout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user instanceof User ? $event->user : null;
        if (! $user && ! ($event instanceof Failed)) {
            return;
        }

        $eventName = match (true) {
            $event instanceof Failed, $event instanceof Login && ! $user->is_active => 'auth.failed',
            $event instanceof Login => 'auth.login',
            default => 'auth.logout',
        };
        $attemptedEmail = $event instanceof Failed && is_string($event->credentials['email'] ?? null)
            ? mb_substr($event->credentials['email'], 0, 254) : null;

        DB::table('audit_logs')->insert([
            'user_id' => $user?->id,
            'event' => $eventName,
            'auditable_type' => $user ? User::class : null,
            'auditable_id' => $user?->id,
            'new_values' => json_encode([
                'name' => $user?->name,
                'email' => $attemptedEmail ?? $user?->email,
                'roles' => $user?->getRoleNames()->all() ?? [],
                'remember' => $event instanceof Login ? $event->remember : null,
            ], JSON_THROW_ON_ERROR),
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
