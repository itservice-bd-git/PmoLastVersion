<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinet_checklists', function (Blueprint $table) {
            $table->date('target_date')->nullable()->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_checklists', function (Blueprint $table) {
            $table->dropColumn('target_date');
        });
    }
};
