<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentFieldOption extends Model
{
    protected $fillable = ['department_field_id', 'group_name', 'label', 'sort'];

    public function field()
    {
        return $this->belongsTo(DepartmentField::class, 'department_field_id');
    }
}
