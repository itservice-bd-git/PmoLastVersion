<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRule extends Model
{
    const TRIGGER_FIRST_DONE = 'checklist_first_done';

    const TRIGGER_ALL_DONE = 'checklist_all_done';

    const ACTION_START = 'start';

    const ACTION_COMPLETE = 'complete';

    public static array $triggers = [
        self::TRIGGER_FIRST_DONE => 'ติ๊ก Checklist ข้อแรก',
        self::TRIGGER_ALL_DONE => 'ติ๊ก Checklist ครบทุกข้อ',
    ];

    public static array $actions = [
        self::ACTION_START => 'เริ่มงานอัตโนมัติ',
        self::ACTION_COMPLETE => 'ปิดงาน (เสร็จงาน) อัตโนมัติ',
    ];

    protected $fillable = ['name', 'department_id', 'trigger', 'action', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'department_id' => 'integer'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
