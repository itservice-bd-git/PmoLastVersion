<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การจัดส่ง: PM asks for a kind of vehicle and how many people (vehicle_type, crew_size); the delivery department picks the
 * vehicle and the people who go (vehicle_id, driver_name + crew). Each vehicle has its kind (Settings > รถขนส่ง).
 * A round may be a draft (status 'draft', kept by PM, not sent yet); its cabinet_ids may be empty = every cabinet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('type', 50)->nullable()->after('name');   // "6 ล้อเครน", "10 ล้อ", "กระบะ"
        });
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->string('vehicle_type', 50)->nullable()->after('vehicle_id');       // PM: the kind of vehicle it needs
            $table->unsignedTinyInteger('crew_size')->nullable()->after('vehicle_type');  // PM: how many people go, the driver included
            $table->json('crew')->nullable()->after('driver_name');                      // the delivery department: who goes besides the driver
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->dropColumn(['vehicle_type', 'crew_size', 'crew']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
