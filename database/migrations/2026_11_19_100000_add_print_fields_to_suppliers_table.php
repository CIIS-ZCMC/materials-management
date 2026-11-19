<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('tin', 30)->nullable();
            $table->string('contact_no', 50)->nullable();
            $table->string('supply_chain_role', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['tin', 'contact_no', 'supply_chain_role']);
        });
    }
};