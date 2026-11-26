<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทีม: a department may split its people into teams (the sales department has several); the others need none.
 * A user is in one team at most (users.team_id). A project's team is its sales person's - not a column of the project,
 * so the two can never disagree (CLAUDE.md database rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['department_id', 'name'], 'teams_department_name_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('department_id')->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('team_id'));
        Schema::dropIfExists('teams');
    }
};
