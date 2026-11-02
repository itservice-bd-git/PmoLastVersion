<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > สีสถานะ Checklist: putting this status on an item ticks the item too (e.g. "ของเข้าแล้ว")
        Schema::table('checklist_statuses', function (Blueprint $table) {
            $table->boolean('ticks_item')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_statuses', function (Blueprint $table) {
            $table->dropColumn('ticks_item');
        });
    }
};
