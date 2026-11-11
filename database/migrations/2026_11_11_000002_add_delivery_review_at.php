<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รอตรวจ: a round the driver reported ส่งถึงแล้ว through the driver's link (DriverLinkController) - it is delivered, but
 * PM is told only once the delivery department has looked at it (DeliveryFlow::review). null = nothing to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_deliveries', function (Blueprint $table) {
            $table->timestamp('review_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_deliveries', fn (Blueprint $table) => $table->dropColumn('review_at'));
    }
};
