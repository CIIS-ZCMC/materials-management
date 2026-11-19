<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 3.3c: the quantity of a line due within the PO's delivery term, counted from receipt
| of the PO (the first column of form 04A, "N Days from Rec. of P.O."). Nullable: null means
| the PO does not use the split. The "N" is NOT stored; it is purchase_orders.delivery_term_days.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_items', function (Blueprint $table) {
            $table->unsignedInteger('term_quantity')->nullable()->after('quantity_ordered');
        });
    }

    public function down(): void
    {
        Schema::table('po_items', function (Blueprint $table) {
            $table->dropColumn('term_quantity');
        });
    }
};
