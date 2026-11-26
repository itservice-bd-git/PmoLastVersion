<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer is a row of its own (CLAUDE.md database rules), no longer a name typed on each project.
 * - customers: one per distinct customer_name, with a phone from its projects (trashed projects included).
 * - projects.customer_id points at it (RESTRICT: a customer with projects is not deleted). Nullable for now: the old
 *   customer_name / customer_phone columns stay until the clean-up round, so this step loses nothing and the code
 *   from before it still works.
 * - owner_id / sales_person_id filled from project_owner / sales_person where the name is a user's and the id was
 *   empty; a name that is nobody's stays in the text column, to be moved into the project's notes in the clean-up round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('phone', 50)->nullable();
            $table->timestamps();
        });

        DB::table('customers')->insertUsing(['name', 'phone', 'created_at', 'updated_at'],
            DB::table('projects')->selectRaw('customer_name, MAX(customer_phone), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP')
                ->whereNotNull('customer_name')->where('customer_name', '!=', '')->groupBy('customer_name'));

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('project_name')->constrained('customers')->restrictOnDelete();
        });
        DB::table('projects')->update(['customer_id' => DB::raw('(SELECT customers.id FROM customers WHERE customers.name = projects.customer_name)')]);

        foreach (['owner_id' => 'project_owner', 'sales_person_id' => 'sales_person'] as $id => $name) {
            DB::table('projects')->whereNull($id)->whereNotNull($name)
                ->update([$id => DB::raw("(SELECT MIN(users.id) FROM users WHERE users.name = projects.{$name})")]);
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
        // owner_id / sales_person_id filled here are left: they name the same people as the text beside them
    }
};
