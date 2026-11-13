<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class, // roles and permissions first
            DummyUserSeeder::class,      // then users, which need those roles
            SupplierSeeder::class,       // fake suppliers (demo data only)
            CatalogSeeder::class,        // categories, units, items (demo data only)
            PurchaseOrderSeeder::class,  // fake POs; needs suppliers, catalog and users (demo data only)
        ]);
    }
}