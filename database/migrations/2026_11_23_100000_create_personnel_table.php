<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 2.3 / 5.0: the dummy personnel directory (stands in for UMIS until Phase 13).
| Read only: nobody edits these rows in the app. The three is_* role columns drive
| the role filter (inspector, end-user, property custodian); one person may hold several.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 30)->unique();
            $table->string('name');
            $table->string('position', 150)->nullable();
            $table->string('office', 150)->nullable(); // office or department
            $table->boolean('is_inspector')->default(false);
            $table->boolean('is_end_user')->default(false);
            $table->boolean('is_property_custodian')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};