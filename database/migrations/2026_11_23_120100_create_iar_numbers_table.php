<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iar_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();           // e.g. 26-1398, C26-0015, D26-0001
            $table->foreignId('iar_series_id')->constrained('iar_series')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 10)->default('used');    // used | void
            // No foreign key on purpose: the deliveries table comes in step 5.2.
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['iar_series_id', 'sequence']);
            $table->index('status');
            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iar_numbers');
    }
};