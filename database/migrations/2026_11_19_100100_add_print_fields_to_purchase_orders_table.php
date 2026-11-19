<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('pr_no', 50)->nullable();
            $table->string('caf_no', 50)->nullable();
            $table->string('mode_of_procurement', 100)->nullable();
            $table->string('remarks')->nullable(); // the free-text line printed under the items
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['pr_no', 'caf_no', 'mode_of_procurement', 'remarks']);
        });
    }
};