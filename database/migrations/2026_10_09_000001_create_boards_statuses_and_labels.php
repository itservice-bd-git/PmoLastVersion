<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The five statuses Planning has always computed from the departments' progress. They stay (a project with no manual status shows one of them). */
    private const BUILTIN = [
        ['key' => 'todo', 'name' => 'รอรับงาน', 'color' => '#98a2b3', 'is_done' => false],
        ['key' => 'doing', 'name' => 'กำลังทำ', 'color' => '#3b82f6', 'is_done' => false],
        ['key' => 'accepted', 'name' => 'รับงานแล้ว', 'color' => '#f59e0b', 'is_done' => false],
        ['key' => 'done', 'name' => 'เสร็จแล้ว', 'color' => '#10b981', 'is_done' => true],
        ['key' => 'late', 'name' => 'เกินกำหนด', 'color' => '#ef4444', 'is_done' => false],
    ];

    public function up(): void
    {
        Schema::create('board_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->nullable()->unique();   // set on the five built-in ones only
            $table->string('name', 40);
            $table->string('color', 7);
            $table->boolean('is_done')->default(false);        // shown struck through and counted as finished
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // names / colours chosen before statuses became a table (Settings > สถานะงาน used to store them as a setting)
        $custom = [];
        if (Schema::hasTable('app_settings') && ($row = DB::table('app_settings')->where('key', 'board_statuses')->first())) {
            $custom = json_decode($row->value, true) ?: [];
        }
        $legacyId = ['todo' => 's_todo', 'accepted' => 's_review', 'doing' => 's_doing', 'done' => 's_done', 'late' => 's_fail'];
        foreach (self::BUILTIN as $i => $s) {
            $over = $custom[$legacyId[$s['key']]] ?? [];
            DB::table('board_statuses')->insert([
                'key' => $s['key'], 'name' => $over['name'] ?? $s['name'], 'color' => $over['color'] ?? $s['color'],
                'is_done' => $s['is_done'], 'sort' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('board_labels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('color', 7)->default('#7b68ee');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('board_label_project', function (Blueprint $table) {
            $table->foreignId('board_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['board_label_id', 'project_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('board_id')->nullable()->after('color')->constrained('boards')->nullOnDelete();           // null = the main board
            $table->foreignId('board_status_id')->nullable()->after('board_id')->constrained('board_statuses')->nullOnDelete(); // null = computed
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_status_id');
            $table->dropConstrainedForeignId('board_id');
        });
        Schema::dropIfExists('board_label_project');
        Schema::dropIfExists('board_labels');
        Schema::dropIfExists('boards');
        Schema::dropIfExists('board_statuses');
    }
};
