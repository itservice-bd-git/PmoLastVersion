<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings > แจ้งเตือน Checklist: one rule (App\Services\ChecklistNoticeRules does the sending). */
class ChecklistNoticeRule extends Model
{
    public const EVENTS = [
        'date' => 'ลง / เลื่อนวัน',
        'tick' => 'ติ๊กรายการ',
        'cabinet' => 'ครบทั้งตู้',
        'before' => 'ก่อนถึงวัน',
        'late' => 'เลยวัน',
    ];

    /** Fill-ins a message may carry. */
    public const VARS = ['{รายการ}', '{ตู้}', '{วันที่}', '{n}', '{โครงการ}', '{แผนก}'];

    protected $fillable = ['is_active', 'event', 'department_id', 'cabinet_template_id', 'days', 'repeat', 'message', 'recipients'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'repeat' => 'boolean', 'days' => 'integer', 'recipients' => 'array'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function cabinetTemplate()
    {
        return $this->belongsTo(CabinetTemplate::class);
    }

    /** "เลยวัน 3 วัน", "ครบทั้งตู้" */
    public function eventLabel(): string
    {
        $label = self::EVENTS[$this->event] ?? $this->event;

        return in_array($this->event, ['before', 'late'], true) ? $label.' '.$this->days.' วัน' : $label;
    }

    /** Sent right away (a tick / a date) or in the morning round (before / late). */
    public function isDaily(): bool
    {
        return in_array($this->event, ['before', 'late'], true);
    }
}
