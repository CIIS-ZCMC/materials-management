<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 3.3c: the quantity of each PO line on each scheduled delivery date.
| Form 04A prints one number per line and per date column. Zero is a real value
| ("nothing on this date"), so a PO that uses the split has a row for EVERY line and
| EVERY date. A PO without a split has no rows here (dates only, as before).
| The column "within the delivery term from receipt" is po_items.term_quantity.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_schedule_id')->constrained('po_schedules')->cascadeOnDelete();
            $table->foreignId('po_item_id')->constrained('po_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['po_schedule_id', 'po_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_schedule_items');
    }
};
