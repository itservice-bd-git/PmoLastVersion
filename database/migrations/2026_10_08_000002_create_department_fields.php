<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > ฟิลด์เพิ่มเติม: extra fields a department fills in on its part of a project (Avatar Planning's "ข้อมูลเพิ่มเติม").
        Schema::create('department_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10)->default('select');   // select | text | link
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('department_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_field_id')->constrained()->cascadeOnDelete();
            $table->string('group_name')->nullable();
            $table->string('label');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // The value one department chose for one project: an option id (select) or the typed text / link.
        Schema::create('project_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_field_id')->constrained()->cascadeOnDelete();
            $table->string('value', 500);
            $table->timestamps();
            $table->unique(['project_id', 'department_field_id'], 'project_field_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_field_values');
        Schema::dropIfExists('department_field_options');
        Schema::dropIfExists('department_fields');
    }
};
