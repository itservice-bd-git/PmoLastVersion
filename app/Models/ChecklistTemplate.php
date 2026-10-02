<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_subtask_template_id',
        'name',
        'description',
        'sequence',
    ];

    public function subtaskTemplate()
    {
        return $this->belongsTo(CabinetSubtaskTemplate::class, 'cabinet_subtask_template_id');
    }
}
