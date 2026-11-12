<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The same name may exist with another unit (box vs piece), not twice with the same unit.
            $table->unique(['name', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
