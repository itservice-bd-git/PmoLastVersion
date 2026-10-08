<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "When X happens to a Sub Task's checklist, move the Sub Task along" - see AutomationService.
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // null = applies to every department
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('trigger');   // checklist_first_done | checklist_all_done
            $table->string('action');    // start | complete
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // "Work of the blocked department may not be scheduled later than the anchor department's"
        // (e.g. nothing past QC) - see DateLimitRule::violationFor().
        Schema::create('date_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anchor_department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('blocked_department_id')->constrained('departments')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // explicit name: the auto-generated one is 66 chars, over MySQL's 64-char identifier limit
            $table->unique(['anchor_department_id', 'blocked_department_id'], 'date_limit_rules_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('date_limit_rules');
        Schema::dropIfExists('automation_rules');
    }
};
