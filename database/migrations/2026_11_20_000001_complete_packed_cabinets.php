<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A cabinet is made (ผลิตเสร็จ) once its Checklist is all ticked and it is packed (ProgressService::builtAndPacked): the
 * cabinets already like that, still "in progress" for want of the delivery steps, are set completed at 100%.
 * Set-based (no UPDATE ... JOIN) so the SQL for phpMyAdmin (pmo:migration-sql) is the update itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        $items = fn (string $ticked) => "SELECT 1 FROM cabinet_checklists cc
            JOIN cabinet_subtasks cs ON cs.id = cc.cabinet_subtask_id AND cs.deleted_at IS NULL
            JOIN cabinet_tasks ct ON ct.id = cs.cabinet_task_id AND ct.deleted_at IS NULL
            WHERE ct.cabinet_id = cabinets.id AND cc.deleted_at IS NULL AND cc.is_completed = {$ticked}";

        DB::table('cabinets')->whereNull('deleted_at')->whereNotIn('status', ['completed', 'on_hold'])
            ->whereRaw('EXISTS ('.$items('1')." AND REPLACE(cc.name, ' ', '') = 'ห่อตู้เสร็จสิ้น')")
            ->whereRaw('NOT EXISTS ('.$items('0')." AND REPLACE(cc.name, ' ', '') NOT IN ('แจ้งเปิดบิล', 'ดำเนินการส่งมอบ', 'ส่งมอบเสร็จสิ้น'))")
            ->update(['status' => 'completed', 'progress' => 100]);
    }

    public function down(): void {}
};
