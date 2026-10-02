<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function cabinetSubtaskTemplates()
    {
        return $this->hasMany(CabinetSubtaskTemplate::class);
    }

    public function cabinetSubtasks()
    {
        return $this->hasMany(CabinetSubtask::class);
    }
}
