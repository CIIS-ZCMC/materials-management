<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_series', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                       // par | sub_par | ics
            $table->unsignedSmallInteger('year');             // four digits, e.g. 2026
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_series');
    }
};
