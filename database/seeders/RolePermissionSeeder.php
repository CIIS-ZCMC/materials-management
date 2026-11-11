<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Structural data: roles and permissions.
 * The lists live in config/mms_access.php.
 * Safe to run in production and safe to run again (firstOrCreate / sync).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Clear Spatie's permission cache before changing anything
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create every permission in the list
        foreach (config('mms_access.permissions') as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Remove permissions the final flow no longer uses
        //    (this also removes them from any role or user that had them)
        foreach (config('mms_access.retired_permissions') as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->delete();
        }

        // 3. Set the default permissions of each role
        //    (the admin can still adjust permissions per user afterwards)
        foreach (config('mms_access.roles') as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}