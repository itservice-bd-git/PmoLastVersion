<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notice (the bell in the top bar). In-app only on purpose: accounts
 * here are plain usernames, not real email addresses (see LoginRequest), so
 * there is nothing reliable to mail to.
 *
 * `key` identifies the event so a reminder isn't sent twice for the same thing
 * (see NotificationService::alreadySent).
 */
class PmoNotice extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public string $url,
        public ?string $key = null,
        public ?int $projectId = null,   // the project it is about ("ของฉัน" on Planning lists projects that tagged / alerted you)
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'key' => $this->key,
            'project_id' => $this->projectId,
        ];
    }
}
