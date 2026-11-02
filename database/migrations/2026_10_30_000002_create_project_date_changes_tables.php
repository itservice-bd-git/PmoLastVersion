<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > สาเหตุการเลื่อน: the reasons a project's dates may be moved for (Admin keeps the list)
        Schema::create('project_date_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color', 20)->default('slate');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // every change of a project's (cabinet_id null) or a cabinet's start / due date once it has one: "postpone" counts as
        // a round (เลื่อนครั้งที่ n), "correct" (a date typed wrong) does not; the reason's name is kept as it was at the time
        Schema::create('project_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('postpone');
            $table->date('old_start_date')->nullable();
            $table->date('new_start_date')->nullable();
            $table->date('old_due_date')->nullable();
            $table->date('new_due_date')->nullable();
            $table->foreignId('reason_id')->nullable()->constrained('project_date_reasons')->nullOnDelete();
            $table->string('reason_name', 100)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'cabinet_id', 'kind']);
        });

        $now = now();
        DB::table('project_date_reasons')->insert(collect([
            ['ลูกค้าขอเลื่อน', 'indigo'], ['รอวัสดุ / อุปกรณ์', 'amber'], ['แบบ / สเปกเปลี่ยน', 'sky'], ['กำลังผลิตไม่พอ', 'orange'],
            ['FAT ไม่ผ่าน / แก้งาน', 'red'], ['รอเอกสาร / ชำระเงิน', 'emerald'], ['อื่น ๆ', 'slate'],
        ])->map(fn ($r, $i) => ['name' => $r[0], 'color' => $r[1], 'sequence' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('project_date_changes');
        Schema::dropIfExists('project_date_reasons');
    }
};
