<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal bookmarks ("ติดดาว") on Sub Tasks - each user's own list, never shown to anyone else.
     */
    public function up(): void
    {
        Schema::create('subtask_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cabinet_subtask_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'cabinet_subtask_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtask_stars');
    }
};
