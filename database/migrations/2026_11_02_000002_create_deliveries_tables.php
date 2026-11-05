<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings > รถขนส่ง: the company's own vehicles. Switched off, never deleted while a delivery names it.
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('plate', 30)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();   // about how many cabinets it carries (a warning, not a limit)
            $table->string('equipment', 100)->nullable();            // "เครน 3 ตัน", "ลิฟต์ท้าย"
            $table->string('driver_name', 100)->nullable();          // its usual driver
            $table->string('driver_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });

        // การจัดส่ง: one row per round of a project's delivery - which cabinets go, when, where, on what, and how it went
        // (App\Services\DeliveryFlow). PM sends it with a window of days; the delivery department picks the day in it.
        // cabinet_ids = the cabinets of the round (on "ส่งถึงแล้ว": the ones that arrived).
        Schema::create('project_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            $table->date('window_from');                        // PM: it may go any day from ... to ...
            $table->date('window_to');
            $table->date('delivery_date')->nullable();          // the delivery department: the day it goes (null = not picked yet)
            $table->string('delivery_time', 5)->nullable();
            $table->string('status', 20)->default('waiting');   // waiting / rejected / scheduled / departed / delivered
            $table->json('cabinet_ids');
            $table->string('method', 20)->default('company');       // company / external / pickup
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver_name', 100)->nullable();
            $table->string('carrier', 150)->nullable();              // external: the company / the truck
            $table->string('site', 255)->nullable();
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('receiver', 150)->nullable();
            $table->string('doc_no', 50)->nullable();
            $table->string('issue', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();        // who last sent it to the delivery department
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();   // who picked the day
            $table->dateTime('scheduled_at')->nullable();
            // "ไม่รับงาน": the delivery department sent it back (kept until PM sends it again; the log keeps every one)
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejected_reason', 100)->nullable();
            $table->string('rejected_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'round']);
            $table->index(['status', 'window_to']);
            $table->index('delivery_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deliveries');
        Schema::dropIfExists('vehicles');
    }
};
