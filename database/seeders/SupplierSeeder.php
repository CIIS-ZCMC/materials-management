<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY. Refuses to run in production.
 * Safe to run again: suppliers are matched by name.
 */
class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Fake suppliers cannot be seeded in production.');
            return;
        }

        $names = [
            'Mindanao Medical Supply Co.',
            'Zamboanga Surgical Trading',
            'Pacific Health Distributors',
            'Westpoint Pharma Corp.',
            'Sunrise Laboratory Supplies',
            'Cebu MedEquip Inc.',
            'Davao BioTech Traders',
            'Luzon Hospital Solutions',
            'Visayas Pharma Wholesale',
            'Metro Diagnostics Philippines',
            'Golden Gate Office Supplies',
            'Basilan Hardware & Construction',
            'Southern Linen & Uniform Co.',
            'BlueCross Medical Gases',
            'Harbor City Food Services',
            'Prime Janitorial Supply',
            'Orchid Dental Supplies',
            'Apex Biomedical Engineering',
            'Northstar IT Solutions',
            'Cornerstone Printing Press',
            'Evergreen Pharmacy Wholesale',
            'Tropic Surgical Instruments',
            'Island Fuel & Lubricants',
            'Zamboanga Peninsula Electricals',
            'Keystone Medical Imaging',
            'Silverline Packaging Corp.',
            'Rizal Street Stationers',
            'Coastal Water Systems',
            'Unity Hospital Furniture',
            'Bayanihan Health Products',
            'Sampaguita Laundry Services',
            'Mabuhay Biomedical Waste',
            'Crown Safety Equipment',
            'Delta Respiratory Care',
            'Harmony Orthopedic Supplies',
            'Pioneer Generic Medicines',
            'Lakeside Vehicle Services',
            'Granite Civil Works',
            'Starlight Lighting Center',
            'Vista Dental & Optical',
        ];

        foreach ($names as $index => $name) {
            $details = Supplier::factory()->make()->only(['email', 'contact_person', 'address']);

            Supplier::firstOrCreate(
                ['name' => $name],
                $details + ['is_active' => ($index + 1) % 10 !== 0], // every 10th is inactive
            );
        }

        $this->command->info('Fake suppliers seeded: '.count($names));
    }
}