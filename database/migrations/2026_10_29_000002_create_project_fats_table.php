<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // วัน FAT (the customer's acceptance test at the factory), per project, one row per round: a failed FAT keeps its row
        // and the next appointment is round + 1. cabinet_ids null = every cabinet of the project.
        Schema::create('project_fats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            $table->date('fat_date');
            $table->string('fat_time', 5)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->json('cabinet_ids')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'round']);
            $table->index('fat_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fats');
    }
};
