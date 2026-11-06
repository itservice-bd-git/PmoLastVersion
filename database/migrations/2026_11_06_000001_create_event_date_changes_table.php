<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // every move of a round's วัน FAT / วันส่ง once it was set (App\Services\EventDates), as project_date_changes keeps the
        // project's own dates: "postpone" counts (เลื่อนครั้งที่ n), "correct" (typed wrong) does not. event_id has no foreign
        // key: the history stays when its round is deleted / cancelled (it still names the round)
        Schema::create('event_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 20);
            $table->unsignedBigInteger('event_id');
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('kind', 20)->default('postpone');
            $table->date('old_date')->nullable();
            $table->date('old_end_date')->nullable();
            $table->string('old_time', 5)->nullable();
            $table->date('new_date')->nullable();
            $table->date('new_end_date')->nullable();
            $table->string('new_time', 5)->nullable();
            $table->foreignId('reason_id')->nullable()->constrained('project_date_reasons')->nullOnDelete();
            $table->string('reason_name', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'event', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_date_changes');
    }
};
