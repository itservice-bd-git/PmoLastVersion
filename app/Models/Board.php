<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An extra calendar (e.g. Service). The main board is "no board" (projects.board_id null). */
class Board extends Model
{
    protected $fillable = ['name', 'sort'];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
