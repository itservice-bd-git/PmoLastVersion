<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลิงก์ GPS / แผนที่ of a delivery round (a Google Maps link, or coordinates turned into one): PM puts it on with the site,
 * the delivery department with the vehicles; the crew's message, the driver's link and the cards open it (ProjectDelivery::navUrl).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->string('map_url', 500)->nullable()->after('site');
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', fn (Blueprint $table) => $table->dropColumn('map_url'));
    }
};
