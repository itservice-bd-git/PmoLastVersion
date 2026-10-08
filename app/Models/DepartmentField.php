<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentField extends Model
{
    public const TYPES = ['select' => 'ตัวเลือก (dropdown)', 'text' => 'กล่องข้อความ', 'link' => 'ลิงก์'];

    protected $fillable = ['department_id', 'name', 'type', 'sort'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function options()
    {
        return $this->hasMany(DepartmentFieldOption::class)->orderBy('sort')->orderBy('id');
    }
}
