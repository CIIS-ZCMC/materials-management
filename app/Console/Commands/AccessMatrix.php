<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AccessMatrix extends Command
{
    protected $signature = 'mms:access-matrix {--md : Print as a Markdown table}';

    protected $description = 'Print the role-and-permission matrix from config/mms_access.php';

    public function handle(): int
    {
        $permissions = config('mms_access.permissions', []);

        // Columns: the 5 roles first, then one column per MMS job preset
        $columns = [];

        foreach (config('mms_access.roles', []) as $roleName => $rolePermissions) {
            $columns[$roleName] = $rolePermissions;
        }

        foreach (config('mms_access.job_presets', []) as $jobKey => $preset) {
            $label = 'Job: ' . ($preset['label'] ?? $jobKey);
            $columns[$label] = $preset['permissions'] ?? [];
        }

        $headers = array_merge(['Permission'], array_keys($columns));

        $rows = [];
        foreach ($permissions as $permission) {
            $row = [$permission];
            foreach ($columns as $granted) {
                $row[] = in_array($permission, $granted, true) ? 'X' : '';
            }
            $rows[] = $row;
        }

        if ($this->option('md')) {
            $this->line('| ' . implode(' | ', $headers) . ' |');
            $this->line('|' . str_repeat('---|', count($headers)));
            foreach ($rows as $row) {
                $this->line('| ' . implode(' | ', $row) . ' |');
            }
        } else {
            $this->table($headers, $rows);
        }

        $this->newLine();
        $this->line('Total permissions: ' . count($permissions));
        $this->line('Retired: ' . implode(', ', config('mms_access.retired_permissions', [])));

        // Safety check: every permission used by a role or preset must be in the list
        foreach ($columns as $name => $granted) {
            foreach (array_diff($granted, $permissions) as $unknown) {
                $this->error("{$name} uses an unknown permission: {$unknown}");
            }
        }

        return self::SUCCESS;
    }
}