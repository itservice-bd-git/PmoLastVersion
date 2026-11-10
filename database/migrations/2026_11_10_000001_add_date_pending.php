<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "รอวันใหม่": the project's due date is to move but the new one is not known yet (waiting on sales / the customer), so the
 * project is not late meanwhile (App\Services\ProjectDates). A FAT round or a delivery round may wait with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('due_pending_at')->nullable()->after('due_date');          // waiting since; null = not waiting
            $table->string('due_pending_from', 20)->nullable()->after('due_pending_at');  // sales / customer / site / other
            $table->foreignId('due_pending_user_id')->nullable()->after('due_pending_from')->constrained('users')->nullOnDelete();   // who owes the answer
            $table->date('due_pending_follow_up')->nullable()->after('due_pending_user_id');   // ask again on this day
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
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('due_pending_user_id');
            $table->dropColumn(['due_pending_at', 'due_pending_from', 'due_pending_follow_up']);
        });
    }
};
