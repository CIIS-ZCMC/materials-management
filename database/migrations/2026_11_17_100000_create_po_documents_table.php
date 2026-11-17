<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('po_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();

            // For Phase 5 (DR, OR, IAR belong to a delivery). No foreign key yet:
            // the deliveries table does not exist. Phase 5 adds the constraint.
            $table->unsignedBigInteger('delivery_id')->nullable()->index();

            $table->string('type', 40);
            $table->string('original_name');

            // The PDF used for merging (step 4.3): the uploaded PDF itself, or the
            // one-page PDF made from a JPG/PNG at upload.
            $table->string('stored_path')->unique();

            // The uploaded JPG/PNG, kept as is. Null when the upload was a PDF.
            $table->string('original_path')->nullable();

            // Describe the file the user uploaded (not the derived PDF).
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            // Page order of the consolidated PDF: first upload = first pages.
            // A voided document keeps its number, so numbers are never reused.
            $table->unsignedInteger('sort_order');

            $table->string('status', 20)->default('active');
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_documents');
    }
};