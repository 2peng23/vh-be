<?php

namespace App\Support;

class PermissionCatalog
{
    public const MODULES = [
        'dashboard' => ['view'],
        'vehicles' => ['view', 'create', 'update', 'delete'],
        'mileage' => ['view', 'create', 'update', 'delete', 'override'],
        'maintenance' => ['view', 'create', 'update', 'delete'],
        'schedules' => ['view', 'create', 'update', 'delete'],
        'expenses' => ['view', 'create', 'update', 'delete'],
        'documents' => ['view', 'create', 'update', 'delete'],
        'issues' => ['view', 'create', 'update', 'delete'],
        'fuel' => ['view', 'create', 'update', 'delete'],
        'drivers' => ['view', 'create', 'update', 'delete'],
        'assignments' => ['view', 'create', 'update', 'delete'],
        'reports' => ['view', 'export'],
        'notifications' => ['view', 'update'],
        'audit' => ['view', 'export'],
        'staff' => ['view', 'create', 'update', 'delete'],
    ];

    public const TENANT_ROLES = ['owner', 'staff'];

    public static function all(): array
    {
        return collect(self::MODULES)
            ->flatMap(fn (array $actions, string $module) => collect($actions)->map(fn (string $action) => "{$module}.{$action}"))
            ->values()->all();
    }

    public static function defaults(string $role): array
    {
        $all = collect(self::all());

        return match ($role) {
            'owner' => $all->all(),
            'staff' => $all->reject(fn ($permission) => str_ends_with($permission, '.delete') || in_array($permission, ['mileage.override', 'audit.view', 'audit.export', 'staff.view', 'staff.create', 'staff.update', 'staff.delete']))->all(),
            default => [],
        };
    }
}
