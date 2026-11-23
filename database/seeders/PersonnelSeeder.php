<?php

namespace Database\Seeders;

use App\Models\Personnel;
use Illuminate\Database\Seeder;

/**
 * Demo data only: 30 clearly fake people (surnames Sample, Dummy, Demo, Mockup, Fakename,
 * Pretend, Testing, Placeholder). Refuses to run in production: real people come from UMIS
 * (Phase 13). Safe to run again: rows are matched on employee_no.
 *
 * Roles column: I = inspector, E = end-user, C = property custodian, X = inactive.
 */
class PersonnelSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private const PEOPLE = [
        ['Ana B. Sample', 'Engineer III', 'Engineering and Facilities', 'I'],
        ['Ben C. Dummy', 'Engineer II', 'Engineering and Facilities', 'I'],
        ['Carla D. Demo', 'Pharmacist III', 'Pharmacy', 'IE'],
        ['Dino E. Mockup', 'Pharmacist II', 'Pharmacy', 'I'],
        ['Elsa F. Fakename', 'Medical Technologist III', 'Laboratory', 'IE'],
        ['Fred G. Pretend', 'Medical Technologist II', 'Laboratory', 'I'],
        ['Gina H. Testing', 'Radiologic Technologist III', 'Radiology', 'IE'],
        ['Hugo I. Placeholder', 'Supply Officer III', 'Supply Section', 'IC'],
        ['Iris J. Sample', 'Supply Officer II', 'Supply Section', 'IC'],
        ['Jun K. Dummy', 'Administrative Officer IV', 'Administrative Service', 'IE'],
        ['Kara L. Demo', 'Chief Nurse', 'Nursing Service', 'E'],
        ['Leo M. Mockup', 'Nurse IV', 'Nursing Service', 'E'],
        ['Mina N. Fakename', 'Nurse III', 'Emergency Room', 'E'],
        ['Nico O. Pretend', 'Nurse III', 'Operating Room', 'E'],
        ['Olga P. Testing', 'Nurse II', 'Pediatrics Ward', 'E'],
        ['Paco Q. Placeholder', 'Nurse II', 'Medicine Ward', 'E'],
        ['Rica R. Sample', 'Dietitian III', 'Dietary Service', 'E'],
        ['Sam S. Dummy', 'Medical Records Officer', 'Medical Records', 'E'],
        ['Tina T. Demo', 'Accountant II', 'Accounting Section', 'E'],
        ['Uno U. Mockup', 'IT Officer I', 'Information Technology', 'E'],
        ['Vera V. Fakename', 'Storekeeper', 'Property Section', 'C'],
        ['Wally W. Pretend', 'Administrative Assistant III', 'Property Section', 'C'],
        ['Xena X. Testing', 'Property Custodian', 'Property Section', 'C'],
        ['Yoyo Y. Placeholder', 'Administrative Aide VI', 'Pharmacy', 'EC'],
        ['Zed Z. Sample', 'Dentist II', 'Dental Clinic', 'E'],
        ['Abel A. Dummy', 'Physical Therapist II', 'Rehabilitation', 'E'],
        ['Bea B. Demo', 'Biomedical Engineer', 'Biomedical Section', 'IE'],
        ['Cris C. Mockup', 'Security Officer', 'Security Service', 'E'],
        ['Dani D. Fakename', 'Laundry Supervisor', 'Linen and Laundry', 'E'],
        ['Gus G. Placeholder', 'Engineer I', 'Engineering and Facilities', 'IX'], // inactive, to show it is hidden
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('PersonnelSeeder is demo data and refuses to run in production. Real people come from UMIS.');

            return;
        }

        foreach (self::PEOPLE as $index => [$name, $position, $office, $roles]) {
            Personnel::query()->updateOrCreate(
                ['employee_no' => sprintf('UMIS-DUMMY-%03d', $index + 1)],
                [
                    'name' => $name,
                    'position' => $position,
                    'office' => $office,
                    'is_inspector' => str_contains($roles, 'I'),
                    'is_end_user' => str_contains($roles, 'E'),
                    'is_property_custodian' => str_contains($roles, 'C'),
                    'is_active' => ! str_contains($roles, 'X'),
                ],
            );
        }
    }
}