<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > สีสถานะ Checklist: coloured tags an item can carry, several at once (e.g. "ของสั่งนอก" in red).
        // Statuses sharing a group_name are exclusive ("รอของ" / "ของเข้าแล้ว"); department_ids null = every department.
        Schema::create('checklist_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);
            $table->string('description', 120)->nullable();
            $table->string('color', 20);
            $table->string('group_name', 40)->nullable();
            $table->json('department_ids')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cabinet_checklist_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cabinet_checklist_id', 'checklist_status_id'], 'cabinet_checklist_status_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_checklist_status');
        Schema::dropIfExists('checklist_statuses');
    }
};
