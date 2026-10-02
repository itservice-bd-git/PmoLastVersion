<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('sales_person')->nullable()->after('sales_person_id');
            $table->string('project_owner')->nullable()->after('owner_id');
            $table->string('payment_terms')->nullable()->after('remark');
            $table->unsignedBigInteger('job_type_id')->nullable()->after('priority');
        });

        // Backfill from the old user-FK columns so existing projects keep their data.
        DB::statement('UPDATE projects p JOIN users u ON p.owner_id = u.id SET p.project_owner = u.name WHERE p.owner_id IS NOT NULL');
        DB::statement('UPDATE projects p JOIN users u ON p.sales_person_id = u.id SET p.sales_person = u.name WHERE p.sales_person_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['sales_person', 'project_owner', 'payment_terms', 'job_type_id']);
        });
    }
};
