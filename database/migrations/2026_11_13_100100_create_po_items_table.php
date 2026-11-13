<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            // Null when a BizBox line does not match the catalog.
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->string('description', 500); // snapshot of the item name on the PO
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('unit_price', 14, 2);
            $table->unsignedInteger('quantity_ordered');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_items');
    }
};