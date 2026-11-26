<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ทีม of a department (Settings › แผนก): the sales department has several. A user is in one team at most; a project
 * belongs to its sales person's team (the โครงการ list's ทีม filter, App\Services\ProjectList).
 */
class Team extends Model
{
    protected $fillable = ['department_id', 'name'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
