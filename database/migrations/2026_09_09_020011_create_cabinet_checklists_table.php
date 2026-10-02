<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinet_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_subtask_id')->constrained('cabinet_subtasks')->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->nullable()->constrained('checklist_templates')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_checklists');
    }
};
