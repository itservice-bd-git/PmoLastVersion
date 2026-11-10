<?php

namespace App\Models;

use App\Models\Concerns\GuardsClosedProject;
use App\Support\EventStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** วัน FAT: one round of a project's factory acceptance test (App\Http\Controllers\ProjectFatController). */
class ProjectFat extends Model
{
    use GuardsClosedProject;

    public const SCHEDULED = 'scheduled';

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [self::SCHEDULED => 'นัดแล้ว', self::PASSED => 'ผ่าน', self::FAILED => 'ไม่ผ่าน'];

    protected $fillable = ['project_id', 'round', 'fat_date', 'fat_end_date', 'fat_time', 'date_pending_at', 'status', 'cabinet_ids', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['fat_date' => 'date', 'fat_end_date' => 'date', 'cabinet_ids' => 'array', 'date_pending_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    protected function activityProjectId(): ?int
    {
        return $this->project_id;
    }

    /** Its status as Settings > สถานะงาน names it (EventStatuses 'fat'). */
    public function statusLabel(): string
    {
        return EventStatuses::name('fat', $this->status);
    }

    /** What it shows: passed / failed, else late when it runs past the project's due date, else scheduled. */
    public function stateKey(?Project $project = null): string
    {
        return $this->status !== self::SCHEDULED ? $this->status : ($this->isAfterDue($project) ? 'late' : self::SCHEDULED);
    }

    public function stateColor(?Project $project = null): string
    {
        return EventStatuses::color('fat', $this->stateKey($project));
    }

    /** After the project's due date and not passed yet: the delivery date should move, or the FAT. */
    public function isAfterDue(?Project $project = null): bool
    {
        $due = ($project ?? $this->project)?->due_date;

        return $due && $this->status !== self::PASSED && $this->lastDay()->gt($due);
    }

    /** The latest round cabinet $id is in (a round listing no cabinets is every cabinet's): the project table, the cabinet page. */
    public static function latestFor(\Illuminate\Support\Collection $fats, int $id): ?self
    {
        return $fats->sortByDesc('round')->first(fn (self $f) => ! $f->cabinet_ids || in_array($id, array_map('intval', $f->cabinet_ids), true));
    }

    /** Days to it while it has no result: 0 from its first to its last day, negative once that passed; null once passed / failed. */
    public function daysTo(?Carbon $today = null): ?int
    {
        if ($this->status !== self::SCHEDULED) {
            return null;
        }
        $today ??= today();
        $in = (int) $today->diffInDays($this->fat_date, false);
        $left = (int) $today->diffInDays($this->lastDay(), false);

        return $in > 0 ? $in : ($left >= 0 ? 0 : $left);
    }

    /** The FAT's last day: its end date when it runs over several days, else its one day. */
    public function lastDay(): Carbon
    {
        return $this->fat_end_date ?? $this->fat_date;
    }

    /** How many days it runs (1 = one day). */
    public function days(): int
    {
        return (int) $this->fat_date->diffInDays($this->lastDay()) + 1;
    }

    /** "04/11/2569", or "04–06/11/2569" / "30/10/2569 – 02/11/2569" over several days */
    public function dateRange(): string
    {
        $a = $this->fat_date;
        $b = $this->lastDay();
        if ($a->eq($b)) {
            return $a->thaiDate();
        }

        return $a->format('Y-m') === $b->format('Y-m') ? $a->format('d').'–'.$b->thaiDate() : $a->thaiDate().' – '.$b->thaiDate();
    }

    /** "04/11/2569 · 10:00 น.", "04–06/11/2569 (3 วัน) · 10:00 น." */
    public function when(): string
    {
        return $this->dateRange().($this->days() > 1 ? ' ('.$this->days().' วัน)' : '').($this->fat_time ? ' · '.$this->fat_time.' น.' : '');
    }

    /**
     * The FAT window's starting values (projects/partials/_fat-form): edit round $f, or a new round when $f is null
     * ($latest = the project's latest round, for the title).
     */
    public static function formData(Project $project, ?self $f, ?self $latest): array
    {
        return [
            'url' => $f ? route('project-fats.update', $f) : route('projects.fats.store', $project), 'method' => $f ? 'PUT' : 'POST',
            'title' => $f ? 'แก้วัน FAT'.($f->round > 1 ? ' รอบที่ '.$f->round : '') : ($latest ? 'นัด FAT รอบที่ '.($latest->round + 1) : 'ลงวัน FAT'),
            'fat_date' => $f?->fat_date->format('Y-m-d') ?? '', 'fat_end_date' => $f?->fat_end_date?->format('Y-m-d') ?? '', 'fat_time' => (string) $f?->fat_time, 'status' => $f?->status ?? 'scheduled',
            'scope' => $f?->cabinet_ids ? 'some' : 'all', 'cabinet_ids' => array_map('intval', $f?->cabinet_ids ?? []), 'note' => (string) $f?->note,
            'deleteUrl' => $f ? route('project-fats.destroy', $f) : null,
            // its day as set, to tell a move (ประวัติการเลื่อน: App\Services\EventDates)
            'was' => $f ? implode('|', [$f->fat_date->format('Y-m-d'), (string) $f->fat_end_date?->format('Y-m-d'), (string) $f->fat_time]) : '', 'was_text' => $f ? $f->when() : '',
            'moves' => $f ? EventDateChange::where('event', 'fat')->where('event_id', $f->id)->where('kind', EventDateChange::POSTPONE)->count() : 0,
            'move_kind' => EventDateChange::POSTPONE, 'move_reason_id' => 0, 'move_note' => '',
        ];
    }
}
