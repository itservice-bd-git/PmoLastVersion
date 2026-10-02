<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('cabinet_template_id')->nullable()->constrained('cabinet_templates')->nullOnDelete();
            $table->string('mo_no')->unique();
            $table->string('cabinet_name');
            $table->string('cabinet_type')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('not_started');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->decimal('weight', 5, 2)->default(1);
            $table->text('remark')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};
