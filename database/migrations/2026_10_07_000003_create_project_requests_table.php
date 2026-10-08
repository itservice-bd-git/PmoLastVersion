<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests submitted on the public form (/request). They sit here as "pending" until an
     * admin/PM imports one as a Project or rejects it - nothing public ever touches `projects`.
     */
    public function up(): void
    {
        Schema::create('project_requests', function (Blueprint $table) {
            $table->id();
            $table->string('requester_name');
            $table->string('contact');                       // phone / e-mail / LINE - free text, the requester's choice
            $table->string('customer_name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->nullable(); // number of cabinets, if known
            $table->date('needed_by')->nullable();
            $table->string('status')->default('pending');    // pending | imported | rejected
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_requests');
    }
};
