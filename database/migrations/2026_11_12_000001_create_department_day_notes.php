<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หมายเหตุรายวัน: a department's notes for one day (Planning's เดือน, the badge on the day). Many per day; seen by the
 * department's own people only; the one who wrote a note changes or deletes it (DayNoteController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_day_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('body');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['department_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_day_notes');
    }
};
