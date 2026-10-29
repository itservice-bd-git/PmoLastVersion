<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a pair "ดูอย่างเดียว": the Sub Task only follows the other's list (e.g. Production watching จัดซื้อ → คลัง), it gets
        // no copies of its items, ticks nothing there and its progress is its own (SharedChecklist, HandoffService)
        foreach (['cabinet_subtask_template_pairs', 'cabinet_subtask_pairs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('view_only')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['cabinet_subtask_template_pairs', 'cabinet_subtask_pairs'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('view_only');
            });
        }
    }
};
