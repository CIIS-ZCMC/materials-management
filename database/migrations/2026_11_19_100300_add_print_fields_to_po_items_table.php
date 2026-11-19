<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_items', function (Blueprint $table) {
            $table->string('stock_no', 50)->nullable(); // snapshot of items.stock_no
            $table->string('brand', 150)->nullable();
            $table->string('manufacturer', 150)->nullable();
            $table->string('country_of_origin', 100)->nullable();
            $table->string('specifications', 500)->nullable(); // free text, used by form 13
        });
    }

    public function down(): void
    {
        Schema::table('po_items', function (Blueprint $table) {
            $table->dropColumn(['stock_no', 'brand', 'manufacturer', 'country_of_origin', 'specifications']);
        });
    }
};