<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $grants = [
            'record_payments' => ['admin', 'owner', 'manager', 'cashier', 'front_desk'],
            'post_pos_sales' => ['admin', 'owner', 'manager', 'cashier', 'front_desk'],
            'update_kitchen_status' => ['admin', 'owner', 'manager', 'cashier', 'front_desk', 'kitchen'],
            'view_all_shifts' => ['admin', 'owner'],
            'manage_any_shifts' => ['admin', 'owner'],
        ];
        foreach ($grants as $name => $roleNames) {
            $permission = Permission::findOrCreate($name, 'web');
            $legacyName = match ($name) {
                'record_payments' => 'manage_folios',
                'post_pos_sales', 'update_kitchen_status' => 'manage_pos',
                default => 'manage_shifts',
            };
            $legacyPermission = Permission::where('guard_name', 'web')->where('name', $legacyName)->first();
            foreach (Role::where('guard_name', 'web')->whereIn('name', $roleNames)->get() as $role) {
                if ($legacyPermission && $role->hasPermissionTo($legacyPermission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('guard_name', 'web')->whereIn('name', [
            'record_payments', 'post_pos_sales', 'update_kitchen_status', 'view_all_shifts', 'manage_any_shifts',
        ])->get()->each(fn (Permission $permission): bool => $permission->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
