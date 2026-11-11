<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * DEMO DATA ONLY. Refuses to run in production.
 * Requires RolePermissionSeeder to have run first (roles must exist).
 *
 * MMS Staff users get one job preset from config/mms_access.php
 * (given to them as direct permissions, like the admin screen will do).
 */
class DummyUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Dummy users cannot be seeded in production.');
            return;
        }

        // [employee_no, name, email, position, role, job preset or null]
        $users = [
            ['DUMMY-0001', 'Dummy Admin',          'admin@example.test',        'System Admin',      'Admin',       null],
            ['DUMMY-0002', 'Dummy Procurement',    'procurement@example.test',  'Procurement Staff', 'Procurement', null],
            ['DUMMY-0003', 'Dummy PO Reviewer',    'po.reviewer@example.test',  'PO Reviewer',       'MMS Staff',   'po-reviewer'],
            ['DUMMY-0004', 'Dummy Encoder',        'encoder@example.test',      'Encoder',           'MMS Staff',   'encoder'],
            ['DUMMY-0005', 'Dummy Inventory Team', 'inventory@example.test',    'Inventory Team',    'MMS Staff',   'inventory-team'],
            ['DUMMY-0006', 'Dummy MMS Head',       'mmshead@example.test',      'MMS Head',          'MMS Head',    null],
            ['DUMMY-0007', 'Dummy Finance',        'finance@example.test',      'Finance Cashier',   'Finance',     null],
            ['DUMMY-0008', 'Dummy DV Processor',   'dv.processor@example.test', 'DV Processor',      'MMS Staff',   'dv-processor'],
            ['DUMMY-0009', 'Dummy Messenger',      'messenger@example.test',    'Messenger',         'MMS Staff',   'messenger'],
        ];

        foreach ($users as [$employeeNo, $name, $email, $position, $role, $job]) {
            $user = User::updateOrCreate(
                ['employee_no' => $employeeNo],
                [
                    'name'              => $name,
                    'email'             => $email,
                    'position'          => $position,
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_active'         => true,
                ]
            );

            $user->syncRoles([$role]);
            $user->syncPermissions($this->presetPermissions($job));
        }

        $this->command->info('Dummy users seeded. Default password for all: "password"');
    }

    /**
     * Permissions of a job preset, or an empty list when the user has no job.
     */
    private function presetPermissions(?string $job): array
    {
        if ($job === null) {
            return [];
        }

        $permissions = config("mms_access.job_presets.{$job}.permissions");

        if ($permissions === null) {
            throw new InvalidArgumentException("Unknown job preset: {$job}");
        }

        return $permissions;
    }
}