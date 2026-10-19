<?php

namespace Tests;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /** An explicit actor switch represents signing in as another test client. */
    public function actingAs(Authenticatable $user, mixed $guard = null): static
    {
        $guardName = $guard ?? $this->app['auth']->getDefaultDriver();
        $currentId = $this->app['auth']->guard($guardName)->id();
        if ($currentId !== null && $currentId !== $user->getAuthIdentifier()) {
            $this->app['session']->forget('password_hash_'.$guardName);
        }

        return parent::actingAs($user, $guard);
    }

    protected function approveLatestFinancialRequest(): ApprovalRequest
    {
        $actor = auth()->user();
        $approval = ApprovalRequest::where('status', 'pending')->latest('id')->firstOrFail();
        $reviewer = User::factory()->create(['name' => 'Independent test reviewer']);
        $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['view_all_shifts', 'manage_shifts'] as $permission) {
            $reviewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($reviewer)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Independent fixture review'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($actor);

        return $approval->fresh();
    }

    protected function executeLatestFinancialRequest(): void
    {
        $approval = ApprovalRequest::whereIn('status', ['pending', 'approved'])->latest('id')->firstOrFail();
        if ($approval->status === 'pending') {
            $approval = $this->approveLatestFinancialRequest();
        }
        if ($approval->status === 'approved') {
            $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        }
    }
}
