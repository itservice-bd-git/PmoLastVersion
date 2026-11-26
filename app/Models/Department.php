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
        'color',
        'icon',
        'sees_all',
        'handoff_date_label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sees_all' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** Its teams (Settings › แผนก): the sales department has several, most departments none. */
    public function teams()
    {
        return $this->hasMany(Team::class)->orderBy('name');
    }

    public function cabinetSubtasks()
    {
        return $this->hasMany(CabinetSubtask::class);
    }
}
