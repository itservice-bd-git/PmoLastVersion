<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a Project/Cabinet now moves it to the trash (recoverable) instead of
 * erasing it together with its whole activity history.
 *
 * project_no / mo_no lose their DB-level UNIQUE index: a trashed row still holds
 * its number, so the old index would block re-using it. Uniqueness among live
 * rows is enforced by the form validation instead (see the unique rules in
 * ProjectController/CabinetController, which now ignore trashed rows).
 */
return new class extends Migration
{
    private const TABLES = ['projects', 'cabinets', 'cabinet_tasks', 'cabinet_subtasks', 'cabinet_checklists'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['project_no']);
            $table->index('project_no');
        });

        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropUnique(['mo_no']);
            $table->index('mo_no');
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table) {
            $table->dropIndex(['mo_no']);
            $table->unique('mo_no');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['project_no']);
            $table->unique('project_no');
        });

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
