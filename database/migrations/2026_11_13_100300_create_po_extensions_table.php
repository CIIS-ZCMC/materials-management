<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('extra_days');
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at');
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_extensions');
    }
};