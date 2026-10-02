<?php

/**
 * One-time diagnostic/reset tool - upload this file to the `public/` folder
 * on production, visit its URL once in a browser, read the result, then
 * DELETE this file from the server (do not leave it live long-term: it has
 * no login check, and anyone who finds the URL could reset your cache).
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== OPcache status ===\n";

if (! function_exists('opcache_get_status')) {
    echo "OPcache is not installed/available on this server at all.\n";
    echo "(If that's true, OPcache is NOT the cause of the stale-data issue - something else is.)\n";
    exit;
}

$status = opcache_get_status(false);

if ($status === false || ($status['opcache_enabled'] ?? false) === false) {
    echo "OPcache extension exists but is DISABLED (opcache.enable=0).\n";
    echo "(If that's true, OPcache is NOT the cause - something else is.)\n";
    exit;
}

echo "OPcache is ENABLED.\n";
echo 'Cached scripts before reset: '.($status['opcache_statistics']['num_cached_scripts'] ?? 'unknown')."\n";

$config = opcache_get_configuration();
$validateTimestamps = $config['directives']['opcache.validate_timestamps'] ?? null;
$revalidateFreq = $config['directives']['opcache.revalidate_freq'] ?? null;
echo 'opcache.validate_timestamps: '.var_export($validateTimestamps, true)."\n";
echo 'opcache.revalidate_freq: '.var_export($revalidateFreq, true)." seconds\n";

if ($validateTimestamps === false) {
    echo "\n>>> FOUND IT: opcache.validate_timestamps is OFF. <<<\n";
    echo ">>> This means PHP NEVER re-checks uploaded files for changes on its own - <<<\n";
    echo ">>> explains exactly why re-uploading files had no effect until reset. <<<\n";
}

$reset = opcache_reset();
echo "\n=== Reset result ===\n";
echo $reset ? "SUCCESS - OPcache cleared. Try the department-dispatch test again now.\n" : "FAILED to reset (opcache.restrict_api may be blocking this).\n";
