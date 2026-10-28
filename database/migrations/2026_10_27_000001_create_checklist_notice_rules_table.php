<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Settings > แจ้งเตือน Checklist: who hears what when a department's paired checklist moves (App\Services\ChecklistNoticeRules). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_notice_rules', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->string('event', 20);   // date / tick / cabinet / before / late
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();   // whose Sub Task's list it watches
            $table->foreignId('cabinet_template_id')->nullable()->constrained()->nullOnDelete();   // null = every template
            $table->unsignedSmallInteger('days')->nullable();   // before / late: working days
            $table->boolean('repeat')->default(false);   // late: every day until dealt with
            $table->string('message', 255);
            $table->json('recipients');   // ["linked", "owner", "pm", "dept:5", "user:7"]
            $table->timestamps();
            $table->index(['event', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_notice_rules');
    }
};
