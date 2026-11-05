<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * การจัดส่ง, second shape (App\Services\DeliveryFlow):
 *  - PM sets the days itself (delivery_date, delivery_end_date for a round over several days, delivery_time) - no window
 *    for the delivery department to pick a day from any more
 *  - PM asks for kinds of vehicle (vehicle_types, several: "10 ล้อ" + "กระบะ") and how many people (crew_size)
 *  - the delivery department puts as many vehicles on the round as it needs, each with its driver and the people who go
 *    (project_delivery_vehicles; an outside carrier's truck has no vehicle_id, only its label)
 *  - packed = cabinet id => when it was packed (ห่อตู้เสร็จสิ้น), billed_* = แจ้งเปิดบิล
 * The rounds already there keep what they had: a round with no day picked takes its window as its days, its kind of
 * vehicle becomes a list of one, its vehicle and people move to the new table. Plain SQL only (it runs on sqlite in tests
 * and is printed for phpMyAdmin by pmo:migration-sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_delivery_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();   // null = an outside carrier's truck
            $table->string('label', 150)->nullable();                                    // that truck: "70-1234"
            $table->string('driver_name', 100)->nullable();
            $table->json('crew')->nullable();                                             // who goes besides the driver
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->index('vehicle_id');
        });

        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->date('delivery_end_date')->nullable()->after('delivery_date');   // a round over several days: its last (null = one day)
            $table->json('vehicle_types')->nullable()->after('vehicle_type');
            $table->json('packed')->nullable()->after('cabinet_ids');
            $table->dateTime('billed_at')->nullable()->after('packed');
            $table->foreignId('billed_by')->nullable()->after('billed_at')->constrained('users')->nullOnDelete();
        });

        DB::update('update project_deliveries set delivery_end_date = window_to where delivery_date is null and window_to > window_from');
        DB::update('update project_deliveries set delivery_date = window_from where delivery_date is null');
        DB::update("update project_deliveries set vehicle_types = json_array(vehicle_type) where vehicle_type is not null and vehicle_type <> ''");
        DB::insert('insert into project_delivery_vehicles (project_delivery_id, vehicle_id, driver_name, crew, sequence, created_at, updated_at)
            select id, vehicle_id, driver_name, crew, 1, updated_at, updated_at from project_deliveries where vehicle_id is not null or driver_name is not null');

        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status', 'window_to']);
            $table->dropConstrainedForeignId('vehicle_id');
        });
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->dropColumn(['window_from', 'window_to', 'vehicle_type', 'driver_name', 'crew']);
            $table->index(['status', 'delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status', 'delivery_date']);
            $table->date('window_from')->nullable()->after('round');
            $table->date('window_to')->nullable()->after('window_from');
            $table->foreignId('vehicle_id')->nullable()->after('method')->constrained()->nullOnDelete();
            $table->string('vehicle_type', 50)->nullable()->after('vehicle_id');
            $table->string('driver_name', 100)->nullable()->after('crew_size');
            $table->json('crew')->nullable()->after('driver_name');
        });
        DB::update('update project_deliveries set window_from = delivery_date, window_to = coalesce(delivery_end_date, delivery_date)');
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->index(['status', 'window_to']);
            $table->dropConstrainedForeignId('billed_by');
            $table->dropColumn(['delivery_end_date', 'vehicle_types', 'packed', 'billed_at']);
        });
        Schema::dropIfExists('project_delivery_vehicles');
    }
};
