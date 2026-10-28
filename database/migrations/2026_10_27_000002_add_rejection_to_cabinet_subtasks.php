<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "ไม่รับงาน": the last time a department sent work back, who, why (SubtaskAssignmentService::reject); cleared when it is handed out again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->foreignId('rejected_department_id')->nullable()->after('completed_at')->constrained('departments')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->after('rejected_department_id')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejected_reason', 500)->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_department_id');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejected_reason']);
        });
    }
};
