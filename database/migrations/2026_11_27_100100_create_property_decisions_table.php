<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_item_id')->constrained('delivery_items')->restrictOnDelete();

            $table->boolean('is_equipment');                  // what the staff decided
            $table->boolean('category_default');              // what the PO line's category said at that moment
            $table->string('override_reason', 500)->nullable(); // required when the two differ

            // Snapshots: the rule as it was when the decision was made. Changing config/mms.php later never rewrites these.
            $table->unsignedBigInteger('unit_price_cents');
            $table->string('band', 20);                       // not_equipment | par | ics | below
            $table->unsignedBigInteger('par_threshold_cents');
            $table->unsignedBigInteger('ics_min_cents');
            $table->boolean('rule_provisional')->default(true);

            $table->string('remarks', 500)->nullable();       // mandatory when band = below

            // No unique index on purpose: voided decisions share the line. "One ACTIVE decision per line" is checked by
            // PropertyDecisionService under a row lock.
            $table->string('status', 10)->default('active');  // active | void
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_decisions');
    }
};
