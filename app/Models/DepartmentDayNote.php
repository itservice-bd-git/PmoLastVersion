<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** หมายเหตุรายวัน: one note of a department for one day (DayNoteController). */
class DepartmentDayNote extends Model
{
    public const MAX = 500;

    protected $fillable = ['department_id', 'date', 'body', 'user_id'];

    protected $casts = ['date' => 'date:Y-m-d'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** as the Planning page reads it */
    public function toBoard(?User $viewer): array
    {
        return [
            'id' => $this->id, 'date' => $this->date->format('Y-m-d'), 'dept' => $this->department_id, 'body' => $this->body,
            'by' => $this->user?->name ?? '-', 'at' => $this->created_at?->format('d/m H:i'),
            'edited' => $this->updated_at && $this->created_at && $this->updated_at->gt($this->created_at),
            'mine' => $viewer && $this->user_id === $viewer->id,
        ];
    }
}
