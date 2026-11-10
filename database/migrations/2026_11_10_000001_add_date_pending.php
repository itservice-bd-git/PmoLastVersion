<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง": the project's dates are put off with no new ones yet - not late, nothing counted or chased
 * meanwhile (App\Services\ProjectDates); its FAT and delivery rounds still to come wait with it, and all get their new dates
 * together when sales has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('due_pending_at')->nullable()->after('due_date');   // รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง since; null = not waiting
        });
        Schema::table('project_fats', function (Blueprint $table) {
            $table->timestamp('date_pending_at')->nullable()->after('fat_time');
        });
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->timestamp('date_pending_at')->nullable()->after('delivery_time');
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', fn (Blueprint $table) => $table->dropColumn('date_pending_at'));
        Schema::table('project_fats', fn (Blueprint $table) => $table->dropColumn('date_pending_at'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('due_pending_at'));
    }
};
