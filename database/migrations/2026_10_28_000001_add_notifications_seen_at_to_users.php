<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // when the person last opened the bell: what arrived since is "ใหม่" (the red number), what came before is "เห็นแล้ว",
        // and a new event starts a new notice instead of adding to one already seen (NotificationService::collapse)
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_seen_at')->nullable();
        });
        // everything sent before this change counts as seen, so nobody gets a burst of old notices as new
        DB::statement('UPDATE users SET notifications_seen_at = CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_seen_at');
        });
    }
};
