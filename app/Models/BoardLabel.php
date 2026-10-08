<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardLabel extends Model
{
    protected $fillable = ['name', 'color', 'sort'];

    public function projects()
    {
        return $this->belongsToMany(Project::class);
    }
}
