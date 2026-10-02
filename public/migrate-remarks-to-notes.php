<?php

/**
 * One-off data migration: moves the old single-value Remark text on
 * Projects and Cabinets into the Activity Log as the first manual note,
 * since the Remark field/form has been removed from the app.
 *
 * Run once via browser, confirm the output, then DELETE this file.
 * Safe to run more than once by accident - it skips projects/cabinets that
 * already have a migrated note (checks activity_logs for action=remark_migrated).
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

header('Content-Type: text/plain; charset=utf-8');

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Project;

$migrated = 0;

Project::whereNotNull('remark')->where('remark', '!=', '')->each(function (Project $project) use (&$migrated) {
    $already = ActivityLog::where('loggable_type', Project::class)
        ->where('loggable_id', $project->id)
        ->where('action', 'remark_migrated')
        ->exists();

    if ($already) {
        echo "SKIP project {$project->project_no} (already migrated)\n";

        return;
    }

    ActivityLog::record($project->id, null, $project, 'remark_migrated', $project->remark);
    echo "OK project {$project->project_no}: \"{$project->remark}\"\n";
    $migrated++;
});

Cabinet::whereNotNull('remark')->where('remark', '!=', '')->each(function (Cabinet $cabinet) use (&$migrated) {
    $already = ActivityLog::where('loggable_type', Cabinet::class)
        ->where('loggable_id', $cabinet->id)
        ->where('action', 'remark_migrated')
        ->exists();

    if ($already) {
        echo "SKIP cabinet {$cabinet->mo_no} (already migrated)\n";

        return;
    }

    ActivityLog::record($cabinet->project_id, null, $cabinet, 'remark_migrated', $cabinet->remark);
    echo "OK cabinet {$cabinet->mo_no}: \"{$cabinet->remark}\"\n";
    $migrated++;
});

echo "\nDone. Migrated {$migrated} remark(s) into Activity Log notes.\n";
echo "You can delete this file now.\n";
