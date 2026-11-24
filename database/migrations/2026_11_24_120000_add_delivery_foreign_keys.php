<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Step 5.2: the two delivery_id columns that were left without a foreign key in steps 4.2 and 5.1
| (po_documents.delivery_id, iar_numbers.delivery_id) now point at deliveries.id.
|
| Safe by design:
| - If a row already holds a delivery_id (there was no deliveries table before, so it should not),
|   the migration stops with a clear message instead of failing half way.
| - SQLite (the test database) cannot add a foreign key to an existing table, so it is skipped there;
|   MySQL is where the constraint matters.
| - If MySQL complains about incompatible column types (error 3780 / 1215), the delivery_id column
|   is not an unsigned big integer; fix that column first and run the migration again.
*/
return new class extends Migration
{
    private const TABLES = ['po_documents', 'iar_numbers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNotNull('delivery_id')->exists()) {
                throw new RuntimeException("{$table}.delivery_id already holds values, but no delivery exists yet. Clear them first.");
            }
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('delivery_id')->references('id')->on('deliveries')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['delivery_id']);
            });
        }
    }
};
