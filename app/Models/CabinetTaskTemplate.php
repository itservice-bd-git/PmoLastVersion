<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetTaskTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'cabinet_template_id',
        'name',
        'description',
        'weight',
        'schedule_weight',
        'sequence',
    ];

    public function cabinetTemplate()
    {
        return $this->belongsTo(CabinetTemplate::class);
    }

    public function subtaskTemplates()
    {
        return $this->hasMany(CabinetSubtaskTemplate::class)->orderBy('sequence');
    }
}
