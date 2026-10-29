<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings > สีสถานะ Checklist: a coloured tag a cabinet's checklist item can carry (App\Services\ChecklistStatuses). */
class ChecklistStatus extends Model
{
    /** The colours an admin picks from: key => [Thai name, dot, chip background, chip text] (text on background passes AA). */
    public const PALETTE = [
        'red' => ['แดง', '#dc2626', '#fee2e2', '#991b1b'],
        'orange' => ['ส้ม', '#ea580c', '#ffedd5', '#9a3412'],
        'yellow' => ['เหลือง', '#ca8a04', '#fef9c3', '#854d0e'],
        'green' => ['เขียว', '#16a34a', '#dcfce7', '#166534'],
        'teal' => ['เขียวน้ำทะเล', '#0d9488', '#ccfbf1', '#115e59'],
        'sky' => ['ฟ้า', '#0284c7', '#e0f2fe', '#075985'],
        'blue' => ['น้ำเงิน', '#2563eb', '#dbeafe', '#1e40af'],
        'violet' => ['ม่วง', '#7c3aed', '#ede9fe', '#5b21b6'],
        'pink' => ['ชมพู', '#db2777', '#fce7f3', '#9d174d'],
        'slate' => ['เทา', '#475569', '#e2e8f0', '#334155'],
    ];

    protected $fillable = ['name', 'description', 'color', 'group_name', 'department_ids', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['department_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function checklists()
    {
        return $this->belongsToMany(CabinetChecklist::class, 'cabinet_checklist_status');
    }

    /** May an item of this department's work carry it (no department on the work yet: any status). */
    public function fitsDepartment(?int $deptId): bool
    {
        return ! $this->department_ids || ! $deptId || in_array($deptId, array_map('intval', $this->department_ids), true);
    }

    /** What every page draws a chip from. */
    public function payload(): array
    {
        [, $dot, $bg, $fg] = self::PALETTE[$this->color] ?? self::PALETTE['slate'];

        return [
            'id' => $this->id, 'name' => $this->name, 'desc' => (string) $this->description, 'group' => (string) $this->group_name,
            'depts' => array_map('intval', $this->department_ids ?? []), 'active' => $this->is_active,
            'dot' => $dot, 'bg' => $bg, 'fg' => $fg,
        ];
    }
}
