<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetSubtaskTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_task_template_id',
        'name',
        'description',
        'department_id',
        'sequence',
    ];

    protected function casts(): array
    {
        // See the identical comment in CabinetSubtask::casts() - without this,
        // some servers' PDO/MySQL driver settings return this column as a
        // string, breaking the strict `===` comparison the department
        // dropdown (@selected()) relies on in _subtask.blade.php.
        return [
            'department_id' => 'integer',
        ];
    }

    public function taskTemplate()
    {
        return $this->belongsTo(CabinetTaskTemplate::class, 'cabinet_task_template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function checklistTemplates()
    {
        return $this->hasMany(ChecklistTemplate::class)->orderBy('sequence');
    }
}
