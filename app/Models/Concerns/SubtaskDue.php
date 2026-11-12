<?php

namespace App\Models\Concerns;

/** CabinetSubtask: done, late and days left. */
trait SubtaskDue
{
    /**
     * Finished: its checklist is complete, or its department pressed "เสร็จงาน". The one rule every count uses (late work,
     * done counts, reminders, Planning), so the dashboard, the project page and Planning agree (PRODUCT.md "one number").
     */
    public function isDone(): bool
    {
        return $this->status === self::STATUS_COMPLETED || $this->assignment_status === self::ASSIGNMENT_COMPLETED;
    }

    /** Not done and due before $today: the due day itself is not late yet. */
    public function isLateOn(?\Illuminate\Support\Carbon $today = null): bool
    {
        return $this->due_date !== null && ! $this->isDone() && $this->due_date->lt(($today ?? today())->copy()->startOfDay());
    }

    /** The query form of ! isDone(). */
    public function scopeNotDone($query)
    {
        return $query->where('status', '!=', self::STATUS_COMPLETED)->where('assignment_status', '!=', self::ASSIGNMENT_COMPLETED);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isLateOn();
    }

    /**
     * Calendar days left until due_date (negative = overdue by that many days).
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }
}
