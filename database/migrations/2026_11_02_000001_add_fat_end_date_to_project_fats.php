<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a FAT over several days: its last day (null = one day, fat_date only)
        Schema::table('project_fats', function (Blueprint $table) {
            $table->date('fat_end_date')->nullable()->after('fat_date');
        });
    }

    public function down(): void
    {
        Schema::table('project_fats', function (Blueprint $table) {
            $table->dropColumn('fat_end_date');
        });
    }
};
