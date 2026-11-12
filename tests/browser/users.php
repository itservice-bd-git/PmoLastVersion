<?php

/**
 * Two throw-away accounts for tests/browser/smoke.mjs, on the LOCAL database only:
 *   php tests/browser/users.php up     # probe-adm@example.test (admin), probe-ship@example.test (member of the delivery dept)
 *   php tests/browser/users.php down   # removes them
 * The delivery department is the one named "จัดส่ง..." (or set PROBE_SHIP_DEPT=<id>).
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "not on production\n");
    exit(1);
}
$emails = ['probe-adm@example.test', 'probe-ship@example.test'];
if (($argv[1] ?? '') === 'down') {
    App\Models\User::whereIn('email', $emails)->get()->each->forceDelete();
    echo "removed\n";
    exit(0);
}
$ship = getenv('PROBE_SHIP_DEPT') ?: App\Models\Department::where('name', 'like', 'จัดส่ง%')->value('id');
foreach ([[$emails[0], 'admin', null], [$emails[1], 'member', $ship]] as [$email, $role, $dept]) {
    App\Models\User::updateOrCreate(['email' => $email], ['name' => 'probe '.$role, 'password' => Illuminate\Support\Facades\Hash::make('probe-pass-1'),
        'role' => $role, 'department_id' => $dept, 'is_active' => true]);
}
echo "ready (delivery dept id: ".($ship ?? 'none').")\n";
