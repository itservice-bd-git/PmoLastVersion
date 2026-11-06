<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** One move of a round's วัน FAT / วันส่ง (App\Services\EventDates): a round of postponing, or a date typed wrong. */
class EventDateChange extends Model
{
    public const POSTPONE = 'postpone';

    public const CORRECT = 'correct';

    protected $fillable = ['project_id', 'event', 'event_id', 'round', 'kind', 'old_date', 'old_end_date', 'old_time', 'new_date', 'new_end_date', 'new_time',
        'reason_id', 'reason_name', 'note', 'changed_by'];

    /** set by EventDates::history(): เลื่อนครั้งที่ n of its round, null for a correction */
    public ?int $n = null;

    protected function casts(): array
    {
        return ['old_date' => 'date', 'old_end_date' => 'date', 'new_date' => 'date', 'new_end_date' => 'date'];
    }

    public function reason()
    {
        return $this->belongsTo(ProjectDateReason::class, 'reason_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Days the first day moved (+ later, - earlier); 0 when only the end day / the time changed. */
    public function days(): int
    {
        return $this->old_date && $this->new_date ? (int) $this->old_date->diffInDays($this->new_date, false) : 0;
    }

    public function fromText(): string
    {
        return self::text($this->old_date, $this->old_end_date, $this->old_time);
    }

    public function toText(): string
    {
        return self::text($this->new_date, $this->new_end_date, $this->new_time);
    }

    /** "20/11/2569 · 09:00 น.", "20–22/11/2569" */
    public static function text(?Carbon $a, ?Carbon $b, ?string $time): string
    {
        return $a ? ProjectDelivery::range($a, $b && $b->gt($a) ? $b : $a).($time ? ' · '.$time.' น.' : '') : '-';
    }
}
