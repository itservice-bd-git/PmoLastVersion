<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // people following a project (ProjectFollowers): they hear its news - work accepted / done / sent back, comments,
        // the project made - without being in charge of it. source: 'sales' = ticked as its พนักงานขาย on the project form,
        // 'self' = pressed "ติดตามโครงการ" themselves
        Schema::create('project_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('self');
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_followers');
    }
};
