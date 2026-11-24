<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 5.2: inspectors and the end-user of a delivery, as a snapshot of the personnel directory
| (person_id is the directory's id as text, so a UMIS id that is not a number still fits).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('person_id', 64);
            $table->string('employee_no', 50);
            $table->string('name');
            $table->string('position')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['delivery_id', 'role', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_signatories');
    }
};
