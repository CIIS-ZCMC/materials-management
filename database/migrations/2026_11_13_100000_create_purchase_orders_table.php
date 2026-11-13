<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 50)->unique();
            $table->unsignedBigInteger('supplier_id');
            $table->date('po_date')->nullable();
            $table->date('emailed_date')->nullable();
            $table->unsignedSmallInteger('delivery_term_days')->nullable();
            $table->date('due_date')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('external_id', 100)->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // One PO per BizBox id (several NULL external ids are fine).
            $table->unique(['source', 'external_id']);

            // For the PO list and search. The supplier index is declared before
            // the foreign key so MySQL uses it instead of creating a second one.
            $table->index('status');
            $table->index('due_date');
            $table->index('supplier_id');
            $table->index('po_date');
            $table->index(['status', 'due_date']);

            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};