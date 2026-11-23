<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iar_series', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // regular | contract | donation
            $table->unsignedSmallInteger('year');             // four digits, e.g. 2026
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iar_series');
    }
};