<?php

namespace App\Support;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Reads config/mms_access.php. This is the ONLY class that knows the shape of
 * that config. If your config uses different keys, adjust here and nothing else.
 *
 * Assumed keys:
 *   'permissions'  => flat list of names, OR module => [names], OR name => label
 *   'job_presets'  => key => ['label' => '...', 'permissions' => [...]]
 *   'labels'       => (optional) permission name => label
 */
class MmsAccess
{
    public const ADMIN_ROLE = 'Admin';

    public const BASE_ROLE = 'MMS Staff';

    /** Display names for the module prefix of each permission ("po.view" -> "po"). */
    public const MODULE_LABELS = [
        'po' => 'Purchase Orders',
        'extension' => 'Extensions',
        'suppliers' => 'Suppliers',
        'delivery' => 'Deliveries',
        'documents' => 'Documents',
        'equipment' => 'Equipment',
        'par' => 'PAR / Sub-PAR',
        'ics' => 'ICS',
        'dv' => 'DV',
        'finance' => 'Finance',
        'cancel' => 'Cancellation',
        'replacement' => 'Replacement',
        'delay' => 'Delay monitoring',
        'users' => 'Users',
        'roles' => 'Roles',
        'audit' => 'Audit log',
        'reports' => 'Reports',
    ];

    /** Permissions an admin may never remove from themselves. */
    public const SELF_KEEP = ['users.manage', 'roles.manage'];

    /**
     * Permission groups for the form, one group per module.
     *
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function groups(): array
    {
        $grouped = [];

        foreach ((array) config('mms_access.permissions', []) as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    [$name, $label] = is_string($k) ? [$k, (string) $v] : [(string) $v, null];
                    $grouped[(string) $key][] = ['name' => $name, 'label' => $label ?? self::label($name)];
                }

                continue;
            }

            [$name, $label] = is_string($key) ? [$key, (string) $value] : [(string) $value, null];
            $grouped[Str::before($name, '.')][] = ['name' => $name, 'label' => $label ?? self::label($name)];
        }

        $out = [];
        foreach ($grouped as $module => $permissions) {
            $out[] = [
                'key' => (string) $module,
                'label' => self::MODULE_LABELS[$module] ?? Str::headline((string) $module),
                'permissions' => $permissions,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $permission) {
                $names[] = $permission['name'];
            }
        }

        return $names;
    }

    /**
     * Job presets, limited to permissions that still exist.
     *
     * @return list<array{key: string, label: string, permissions: list<string>}>
     */
    public static function presets(): array
    {
        $valid = self::permissionNames();
        $out = [];

        foreach ((array) config('mms_access.job_presets', []) as $key => $preset) {
            $perms = is_array($preset) ? ($preset['permissions'] ?? $preset) : [];
            $out[] = [
                'key' => (string) $key,
                'label' => (string) (is_array($preset) ? ($preset['label'] ?? Str::headline((string) $key)) : Str::headline((string) $key)),
                'permissions' => array_values(array_intersect(array_values((array) $perms), $valid)),
            ];
        }

        return $out;
    }

    /**
     * Roles with their permissions. Read from the database, which
     * RolePermissionSeeder fills from the config role defaults, so the locked
     * ticks always match what Spatie actually grants.
     *
     * @return list<array{name: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ])
            ->all();
    }

    /** @return list<string> */
    public static function rolePermissions(string $role): array
    {
        $model = Role::query()->where('name', $role)->where('guard_name', 'web')->first();

        return $model ? $model->permissions->pluck('name')->values()->all() : [];
    }

    /**
     * @return array{roles: list<array<string, mixed>>, groups: list<array<string, mixed>>, presets: list<array<string, mixed>>, base_role: string, admin_role: string}
     */
    public static function formOptions(): array
    {
        return [
            'roles' => self::roles(),
            'groups' => self::groups(),
            'presets' => self::presets(),
            'base_role' => self::BASE_ROLE,
            'admin_role' => self::ADMIN_ROLE,
        ];
    }

    /**
     * Job label for an MMS Staff user: the largest preset whose permissions
     * (beyond the base role) are all among the user's direct permissions.
     *
     * @param  list<string>  $direct
     * @param  list<array{label: string, permissions: list<string>}>  $presets
     * @param  list<string>  $basePermissions
     */
    public static function jobLabel(array $direct, array $presets, array $basePermissions): ?string
    {
        $best = null;
        $bestCount = 0;

        foreach ($presets as $preset) {
            $required = array_values(array_diff($preset['permissions'], $basePermissions));

            if ($required === [] || array_diff($required, $direct) !== []) {
                continue;
            }

            if (count($required) > $bestCount) {
                $best = $preset['label'];
                $bestCount = count($required);
            }
        }

        return $best;
    }

    public static function label(string $name): string
    {
        $configured = config("mms_access.labels.{$name}");

        return is_string($configured) ? $configured : Str::headline(Str::contains($name, '.') ? Str::after($name, '.') : $name);
    }
}