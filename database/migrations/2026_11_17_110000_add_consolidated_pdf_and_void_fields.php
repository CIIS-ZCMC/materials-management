<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.3: the consolidated PDF and voiding.
 *
 * purchase_orders: where the ONE combined PDF of the PO is (a path on the private disk) and what
 * state it is in. consolidated_stale = true means the documents changed but the last rebuild
 * failed, so the stored file is the previous version (the page tells the person).
 *
 * po_documents: page_count (pages of the stored PDF, filled by the builder; it drives the page
 * index) and who voided a document and when (void_reason already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('consolidated_path')->nullable();
            $table->unsignedInteger('consolidated_pages')->nullable();
            $table->timestamp('consolidated_built_at')->nullable();
            $table->boolean('consolidated_stale')->default(false);
            $table->string('consolidated_error', 500)->nullable();
        });

        Schema::table('po_documents', function (Blueprint $table) {
            $table->unsignedInteger('page_count')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('po_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['page_count', 'voided_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'consolidated_path',
                'consolidated_pages',
                'consolidated_built_at',
                'consolidated_stale',
                'consolidated_error',
            ]);
        });
    }
};