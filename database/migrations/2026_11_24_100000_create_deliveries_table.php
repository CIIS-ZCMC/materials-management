<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 5.2: one row per delivery batch (one IAR). Header only; per-item delivered quantities
| are step 6.1. amount_cents holds the amount in centavos (App\Support\Money).
| iar_number is a snapshot of the number reserved in iar_numbers (the same transaction writes both).
| A delivery is never deleted; a mistake is voided with a reason and its number stays used.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('status', 30)->default('for_uploading');
            $table->string('invoice_no', 100);
            $table->string('iar_series', 20);
            $table->string('iar_number', 20)->unique();
            $table->string('fund_cluster', 100);
            $table->unsignedBigInteger('amount_cents');
            $table->date('date_received');
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
            $table->index('date_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
