<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'pmo:send-reminders {--days=2 : Remind this many days before the due date}';

    protected $description = 'Notify departments (and PMs for overdue work) about Sub Tasks that are due soon or overdue';

    public function handle(NotificationService $notifications): int
    {
        $sent = $notifications->sendReminders((int) $this->option('days'));

        $this->info("ส่งการแจ้งเตือน {$sent} รายการ");

        return self::SUCCESS;
    }
}
