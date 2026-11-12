<?php

namespace App\Models\Concerns;

/** Project: progress, cabinet summary, risk and days left, as computed attributes. */
trait ProjectProgress
{
    public function getTaskProgressAttribute(): int
    {
        if (array_key_exists('project_tasks_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['project_tasks_avg_progress'] ?? 0);
        }

        return (int) round($this->projectTasks()->avg('progress') ?? 0);
    }

    public function getProductionProgressAttribute(): int
    {
        if (array_key_exists('cabinets_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['cabinets_avg_progress'] ?? 0);
        }

        return (int) round($this->cabinets()->avg('progress') ?? 0);
    }

    public function getCabinetSummaryAttribute(): array
    {
        $cabinets = $this->relationLoaded('cabinets') ? $this->cabinets : $this->cabinets()->get(['status']);

        return [
            'total' => $cabinets->count(),
            'completed' => $cabinets->where('status', 'completed')->count(),
            'in_progress' => $cabinets->where('status', 'in_progress')->count(),
            'not_started' => $cabinets->where('status', 'not_started')->count(),
            'on_hold' => $cabinets->where('status', 'on_hold')->count(),
        ];
    }

    public function getRiskLevelAttribute(): string
    {
        if ($this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELLED) {
            return 'none';
        }

        if (! $this->due_date) {
            return 'none';
        }
        if ($this->isDuePending()) {   // รอฝ่ายขายแจ้งกำหนดส่ง: its due date is not the one to judge it by
            return 'pending';
        }

        $today = now()->startOfDay();

        if ($today->greaterThan($this->due_date) && $this->production_progress < 100) {
            return 'overdue';
        }

        $daysRemaining = $this->days_remaining;

        if ($daysRemaining <= 7 && $this->production_progress < 80) {
            return 'at_risk';
        }

        return 'on_track';
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
