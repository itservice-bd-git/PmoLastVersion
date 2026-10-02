<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the day-allocation share out of `weight`, which is reserved for
 * the future weighted-progress rollup (see README "Weighting is schema-ready
 * but inactive"). Reusing `weight` for both would mean flipping on weighted
 * progress later silently reinterprets whatever schedule shares were set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinet_tasks', function (Blueprint $table) {
            $table->decimal('schedule_weight', 5, 2)->default(0)->after('weight');
        });

        Schema::table('cabinet_task_templates', function (Blueprint $table) {
            $table->decimal('schedule_weight', 5, 2)->default(0)->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_tasks', function (Blueprint $table) {
            $table->dropColumn('schedule_weight');
        });

        Schema::table('cabinet_task_templates', function (Blueprint $table) {
            $table->dropColumn('schedule_weight');
        });
    }
};
