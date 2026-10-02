<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One shared table for both the Project and Cabinet Comment tabs -
        // they already post through the same ActivityLog (action='note') /
        // activity-logs.update flow, so a file attaches to the comment
        // (activity_log) itself rather than needing a project_id/cabinet_id
        // of its own; same column shape as project_attachments/cabinet_attachments.
        Schema::create('comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_log_id')->constrained('activity_logs')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_attachments');
    }
};
