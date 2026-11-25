<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 6.1: one row = the quantity of ONE PO line delivered on ONE date, linked to ONE delivery (one IAR).
| quantity is DECIMAL(12,3): up to 999,999,999.999, so kg and liters can be entered to the gram or milliliter.
| The sums are exact in the database; PHP adds them as whole thousandths (App\Support\Quantity).
| po_items.quantity_ordered stays a whole number on purpose (this step does not touch the PO).
| A row is never edited and never deleted: a mistake is voided with a reason (status "void") and entered again.
| Totals are NEVER stored: delivered = the sum of the ACTIVE rows of the NOT voided deliveries (App\Support\DeliveryTotals).
| No unique key: the same line may be delivered on several dates, and a corrected line is entered again after its void.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->foreignId('po_item_id')->constrained('po_items')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->date('delivered_on');
            $table->string('status', 10)->default('active'); // active | void
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->boolean('over_delivery')->default(false);
            $table->text('over_delivery_reason')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['po_item_id', 'status']);
            $table->index(['delivery_id', 'status']);
            $table->index('delivered_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};
