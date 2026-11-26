<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 5.2b (decision 58): inspectors and the end-user are typed by hand, so a signatory no longer needs a directory id.
|
|  1. A plain index on delivery_id comes FIRST. MySQL may be using the composite unique index
|     (delivery_id, role, person_id) for the delivery_id foreign key; dropping that index alone would fail with error 1553
|     ("needed in a foreign key constraint"). The plain index takes over that job.
|  2. The unique index goes: typed rows have no person_id. A duplicate inspector name is refused by the form request
|     and the service instead.
|  3. person_id and employee_no become nullable. Old rows keep their values.
|
| One Schema::table() call per step keeps SQLite (the tests) happy: it rebuilds the table for change().
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_signatories', function (Blueprint $table) {
            $table->index('delivery_id');
        });

        Schema::table('delivery_signatories', function (Blueprint $table) {
            $table->dropUnique(['delivery_id', 'role', 'person_id']);
        });

        Schema::table('delivery_signatories', function (Blueprint $table) {
            // change() restates the whole column definition (length included).
            $table->string('person_id', 64)->nullable()->change();
            $table->string('employee_no', 50)->nullable()->change();
        });
    }

    /**
     * Reverses up(). It FAILS when rows with a null person_id or employee_no exist (typed rows): delete or fill them first.
     */
    public function down(): void
    {
        Schema::table('delivery_signatories', function (Blueprint $table) {
            $table->string('person_id', 64)->nullable(false)->change();
            $table->string('employee_no', 50)->nullable(false)->change();
        });

        Schema::table('delivery_signatories', function (Blueprint $table) {
            $table->unique(['delivery_id', 'role', 'person_id']);
        });

        Schema::table('delivery_signatories', function (Blueprint $table) {
            $table->dropIndex(['delivery_id']);
        });
    }
};
