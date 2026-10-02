<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->string('assignment_status', 20)->default('UNASSIGNED')->after('department_id')->index();
            $table->foreignId('accepted_by')->nullable()->after('assignment_status')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('accepted_by');
            $table->foreignId('started_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('started_by');
            $table->foreignId('completed_by')->nullable()->after('started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('completed_by');
        });

        // Existing sub tasks that already carry a department count as
        // "assigned, not yet accepted" so the department can still be corrected -
        // except ones whose work is already done (status = completed), which
        // backfill straight to COMPLETED so they don't reappear as pending work.
        DB::table('cabinet_subtasks')->whereNotNull('department_id')->where('status', '!=', 'completed')
            ->update(['assignment_status' => 'ASSIGNED']);
        DB::table('cabinet_subtasks')->whereNotNull('department_id')->where('status', 'completed')
            ->update(['assignment_status' => 'COMPLETED']);
    }

    public function down(): void
    {
        Schema::table('cabinet_subtasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['assignment_status', 'accepted_at', 'started_at', 'completed_at']);
        });
    }
};
