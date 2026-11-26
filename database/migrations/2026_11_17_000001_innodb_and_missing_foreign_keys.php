<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database rules (CLAUDE.md): every table InnoDB, every *_id a foreign key.
 * 1. The MyISAM tables become InnoDB (MyISAM has no foreign keys and no transactions).
 * 2. A reference to a row that is gone is cleared first, where the column may be empty (14 activity logs of projects
 *    deleted for good, locally). A NOT NULL column that points nowhere is not touched: adding its key then fails, so
 *    such a row is looked at by hand (database/sql check file) instead of being deleted here.
 * 3. The seven missing foreign keys.
 * Plain statements only, no rows read first, so `pmo:migration-sql` prints it as it runs.
 */
return new class extends Migration
{
    private const MYISAM = ['activity_logs', 'comment_attachments', 'job_types', 'cabinet_attachments', 'project_attachments',
        // MyISAM on production too (its tables made through phpMyAdmin took the host's default engine), found in the
        // 22 Nov 2026 export: without these the foreign keys of the next migrations can't be made. InnoDB already: a rebuild.
        'cabinet_checklist_status', 'cabinet_subtask_pairs', 'cabinet_subtask_template_pairs', 'checklist_notice_rules',
        'checklist_statuses', 'department_day_notes', 'department_user', 'event_date_changes', 'project_date_changes',
        'project_date_reasons', 'project_deliveries', 'project_delivery_vehicles', 'project_fats', 'project_followers', 'vehicles'];

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::MYISAM as $table) {
                DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
            }
        }

        $gone = fn (string $table, string $column, string $parent) => DB::table($table)->whereNotNull($column)
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from($parent)->whereColumn("{$parent}.id", "{$table}.{$column}"))
            ->update([$column => null]);
        $gone('activity_logs', 'project_id', 'projects');
        $gone('activity_logs', 'user_id', 'users');
        $gone('projects', 'job_type_id', 'job_types');
        // MySQL refuses an UPDATE whose subquery reads the same table (error 1093): read it through a derived table
        DB::table('cabinet_checklists')->whereNotNull('source_checklist_id')
            ->whereNotIn('source_checklist_id', fn (Builder $q) => $q->select('s.id')->fromSub(DB::table('cabinet_checklists')->select('id'), 's'))
            ->update(['source_checklist_id' => null]);

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('comment_attachments', fn (Blueprint $table) => $table->foreign('activity_log_id')->references('id')->on('activity_logs')->cascadeOnDelete());
        Schema::table('cabinet_attachments', fn (Blueprint $table) => $table->foreign('cabinet_id')->references('id')->on('cabinets')->cascadeOnDelete());
        Schema::table('project_attachments', fn (Blueprint $table) => $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete());
        Schema::table('projects', fn (Blueprint $table) => $table->foreign('job_type_id')->references('id')->on('job_types')->nullOnDelete());
        Schema::table('cabinet_checklists', fn (Blueprint $table) => $table->foreign('source_checklist_id')->references('id')->on('cabinet_checklists')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('cabinet_checklists', fn (Blueprint $table) => $table->dropForeign(['source_checklist_id']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropForeign(['job_type_id']));
        Schema::table('project_attachments', fn (Blueprint $table) => $table->dropForeign(['project_id']));
        Schema::table('cabinet_attachments', fn (Blueprint $table) => $table->dropForeign(['cabinet_id']));
        Schema::table('comment_attachments', fn (Blueprint $table) => $table->dropForeign(['activity_log_id']));
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['user_id']);
        });
        // the tables stay InnoDB: going back to MyISAM would only lose what InnoDB gives
    }
};
