<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the old single-value Remark text on Projects and Cabinets into the
 * Activity Log as a first entry (the Remark field/form no longer exists).
 * Replaces the one-off public/migrate-remarks-to-notes.php script, which had no
 * login check. Safe to run on a database where that script already ran: a
 * project/cabinet that already has a 'remark_migrated' row is skipped.
 *
 * Written as INSERT ... SELECT (not a PHP loop) so `php artisan pmo:migration-sql`
 * can print it as plain SQL for phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrate('projects', 'App\\Models\\Project', 'id');
        $this->migrate('cabinets', 'App\\Models\\Cabinet', 'project_id');
    }

    public function down(): void
    {
        // Data move only - nothing to undo (the original remark columns are untouched).
    }

    private function migrate(string $table, string $type, string $projectColumn): void
    {
        $now = now()->toDateTimeString();

        DB::insert("
            INSERT INTO activity_logs (project_id, user_id, loggable_type, loggable_id, action, description, created_at, updated_at)
            SELECT t.{$projectColumn}, NULL, ?, t.id, 'remark_migrated', t.remark, ?, ?
            FROM {$table} t
            WHERE t.remark IS NOT NULL AND t.remark <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM activity_logs a
                  WHERE a.loggable_type = ? AND a.loggable_id = t.id AND a.action = 'remark_migrated'
              )
        ", [$type, $now, $now, $type]);
    }
};
