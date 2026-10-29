<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a pair "ใช้รายการเดียวกัน": the Sub Task gets the copies like one comparing (e.g. Production assembling what คลัง
        // got ready), ticks them for its own work, and its ticks reach nobody else (SharedChecklist::copyTicked)
        foreach (['cabinet_subtask_template_pairs', 'cabinet_subtask_pairs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('own_ticks')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['cabinet_subtask_template_pairs', 'cabinet_subtask_pairs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('own_ticks');
            });
        }
    }
};
