<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectFieldValue extends Model
{
    protected $fillable = ['project_id', 'department_field_id', 'value'];
}
