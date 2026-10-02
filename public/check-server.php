<?php

/**
 * One-time diagnostic - checks whether requests are landing on more than one
 * backend server/database (a load-balanced/clustered hosting setup can cause
 * exactly the "write succeeds, read right after shows old data" symptom,
 * since each backend may have its own DB or its own local disk state).
 * Delete this file after use - no login check.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== Server identity ===\n";
echo 'Hostname: '.gethostname()."\n";
echo 'SERVER_ADDR (this server\'s own IP): '.($_SERVER['SERVER_ADDR'] ?? 'unknown')."\n";
echo 'SERVER_NAME: '.($_SERVER['SERVER_NAME'] ?? 'unknown')."\n";
echo 'Request time: '.date('Y-m-d H:i:s.u')."\n";

echo "\n=== Database connection ===\n";
try {
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
    echo 'Connected DB name: '.\Illuminate\Support\Facades\DB::connection()->getDatabaseName()."\n";

    $row = \Illuminate\Support\Facades\DB::selectOne('SELECT @@hostname AS db_host, @@server_id AS server_id, NOW(6) AS db_time, CONNECTION_ID() AS conn_id');
    echo 'MySQL @@hostname: '.($row->db_host ?? 'n/a')."\n";
    echo 'MySQL @@server_id: '.($row->server_id ?? 'n/a')."\n";
    echo 'MySQL NOW(): '.($row->db_time ?? 'n/a')."\n";
    echo 'MySQL connection id: '.($row->conn_id ?? 'n/a')."\n";

    // The actual thing we care about - read subtask 517 fresh, right now, no caching involved at all.
    $subtask = \Illuminate\Support\Facades\DB::selectOne('SELECT id, department_id, assignment_status, updated_at FROM cabinet_subtasks WHERE id = 517');
    echo "\n=== Subtask 517 right now, this exact request ===\n";
    echo json_encode($subtask, JSON_PRETTY_PRINT)."\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
