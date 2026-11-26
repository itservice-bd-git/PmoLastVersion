<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เลข IV (invoice) of a delivered round: given by the delivery department with ส่งถึงแล้ว, beside the delivery note's
 * number (doc_no). null = not given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->string('invoice_no', 50)->nullable()->after('doc_no');
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', fn (Blueprint $table) => $table->dropColumn('invoice_no'));
    }
};
