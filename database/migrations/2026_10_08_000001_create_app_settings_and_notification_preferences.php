<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System-wide switches an admin flips in Settings (see App\Models\AppSetting for the known keys).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // One row per person who changed their notification choices; no row = every default.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('only_my_department')->default(false);
            $table->boolean('progress')->default(true);     // accepted / started
            $table->boolean('completed')->default(true);
            $table->boolean('reminders')->default(true);    // due soon / overdue
            $table->timestamps();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('description');
            $table->string('icon', 16)->nullable()->after('color');
            $table->boolean('sees_all')->default(false)->after('icon');   // may see every project, like a PMO role (read only)
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn(['color', 'icon', 'sees_all']));
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('app_settings');
    }
};
