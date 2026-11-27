<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                       // par | sub_par | ics
            $table->string('number', 30)->unique();           // e.g. PAR-26-0001
            $table->foreignId('property_series_id')->constrained('property_series')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('property_decision_id')->constrained('property_decisions')->restrictOnDelete();
            // A Sub-PAR points to its PAR. Restrict: a PAR row is never deleted.
            $table->foreignId('parent_id')->nullable()->constrained('property_documents')->restrictOnDelete();

            $table->string('status', 10)->default('issued');  // issued | void

            // Sub-PAR user: a snapshot of the personnel directory (role end-user) at the moment of issue.
            $table->string('user_person_id', 40)->nullable();
            $table->string('user_employee_no', 50)->nullable();
            $table->string('user_name', 255)->nullable();
            $table->string('user_position', 255)->nullable();

            $table->string('void_reason', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['property_series_id', 'sequence']);
            $table->index(['property_decision_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_documents');
    }
};
