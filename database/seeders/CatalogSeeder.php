<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Demo data only: 8 categories, 10 units, 60 hospital supply items.
 * Refuses to run in production. Safe to run again (matches by name).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('CatalogSeeder is demo data and does not run in production.');

            return;
        }

        // name => short code
        $units = [
            'Piece' => 'pc',
            'Box' => 'box',
            'Pack' => 'pack',
            'Bottle' => 'btl',
            'Set' => 'set',
            'Roll' => 'roll',
            'Pair' => 'pr',
            'Gallon' => 'gal',
            'Ream' => 'ream',
            'Unit' => 'unit',
        ];

        foreach ($units as $name => $code) {
            Unit::query()->firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true]);
        }

        // name => is_equipment
        $categories = [
            'Medical Supplies' => false,
            'Surgical Supplies' => false,
            'Laboratory Supplies' => false,
            'Linen and Textiles' => false,
            'Office Supplies' => false,
            'Janitorial Supplies' => false,
            'Medical and Laboratory Equipment' => true,
            'Hospital Furniture' => true,
        ];

        foreach ($categories as $name => $isEquipment) {
            ItemCategory::query()->firstOrCreate(
                ['name' => $name],
                ['is_equipment' => $isEquipment, 'is_active' => true],
            );
        }

        // category => [[item name, unit name], ...]
        $catalog = [
            'Medical Supplies' => [
                ['Disposable Syringe 3 mL', 'Box'],
                ['Disposable Syringe 5 mL', 'Box'],
                ['Disposable Syringe 10 mL', 'Box'],
                ['IV Cannula G22', 'Box'],
                ['IV Cannula G24', 'Box'],
                ['IV Infusion Set, Macrodrip', 'Piece'],
                ['Examination Gloves, Medium', 'Box'],
                ['Examination Gloves, Large', 'Box'],
                ['Face Mask, 3-ply Surgical', 'Box'],
                ['N95 Respirator', 'Box'],
                ['Isopropyl Alcohol 70%, 1 Gallon', 'Gallon'],
                ['Cotton Balls, Absorbent', 'Pack'],
            ],
            'Surgical Supplies' => [
                ['Surgical Gloves, Sterile, Size 7', 'Box'],
                ['Surgical Gloves, Sterile, Size 7.5', 'Box'],
                ['Suture, Silk 2-0', 'Box'],
                ['Suture, Chromic Catgut 2-0', 'Box'],
                ['Suture, Nylon 3-0', 'Box'],
                ['Surgical Blade No. 11', 'Box'],
                ['Surgical Blade No. 15', 'Box'],
                ['Surgical Gown, Sterile', 'Piece'],
            ],
            'Laboratory Supplies' => [
                ['Blood Collection Tube, EDTA', 'Box'],
                ['Blood Collection Tube, Plain', 'Box'],
                ['Blood Collection Tube, Sodium Citrate', 'Box'],
                ['Microscope Slides', 'Box'],
                ['Cover Slips', 'Box'],
                ['Urine Specimen Container', 'Pack'],
                ['Gram Stain Reagent Set', 'Set'],
                ['Glucose Test Strips', 'Box'],
            ],
            'Linen and Textiles' => [
                ['Bed Sheet, White', 'Piece'],
                ['Pillowcase', 'Piece'],
                ['Patient Gown', 'Piece'],
                ['Surgical Drape, Reusable', 'Piece'],
                ['Hospital Blanket', 'Piece'],
                ['Scrub Suit', 'Set'],
            ],
            'Office Supplies' => [
                ['Bond Paper A4, 70 gsm', 'Ream'],
                ['Bond Paper Long, 70 gsm', 'Ream'],
                ['Ballpen, Black', 'Box'],
                ['Ballpen, Blue', 'Box'],
                ['Folder, Long, Manila', 'Pack'],
                ['Stapler, Heavy Duty', 'Piece'],
                ['Staple Wire No. 35', 'Box'],
                ['Printer Ink, Black', 'Bottle'],
            ],
            'Janitorial Supplies' => [
                ['Liquid Hand Soap, 1 Gallon', 'Gallon'],
                ['Chlorine Bleach, 1 Gallon', 'Gallon'],
                ['Disinfectant Solution, 1 Gallon', 'Gallon'],
                ['Garbage Bag, Yellow (Infectious)', 'Roll'],
                ['Garbage Bag, Black', 'Roll'],
                ['Rubber Gloves, Heavy Duty', 'Pair'],
            ],
            'Medical and Laboratory Equipment' => [
                ['Digital Blood Pressure Monitor', 'Unit'],
                ['Pulse Oximeter', 'Unit'],
                ['Infrared Thermometer', 'Unit'],
                ['Nebulizer', 'Unit'],
                ['Suction Machine', 'Unit'],
                ['Stethoscope, Adult', 'Unit'],
                ['Binocular Microscope', 'Unit'],
                ['Benchtop Centrifuge', 'Unit'],
            ],
            'Hospital Furniture' => [
                ['Hospital Bed, Manual', 'Unit'],
                ['Bedside Cabinet', 'Unit'],
                ['IV Stand', 'Unit'],
                ['Examination Table', 'Unit'],
            ],
        ];

        $categoryIds = ItemCategory::query()->pluck('id', 'name');
        $unitIds = Unit::query()->pluck('id', 'name');

        $n = 0;
        foreach ($catalog as $categoryName => $items) {
            foreach ($items as [$name, $unitName]) {
                $n++;

                Item::query()->firstOrCreate(
                    ['name' => $name, 'unit_id' => $unitIds[$unitName]],
                    [
                        'category_id' => $categoryIds[$categoryName],
                        'is_active' => $n % 20 !== 0, // every 20th item inactive, to see the badge
                    ],
                );
            }
        }
    }
}
