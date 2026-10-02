<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetTemplate extends Model
{
    use HasFactory, HasAuditFields;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function taskTemplates()
    {
        return $this->hasMany(CabinetTaskTemplate::class)->orderBy('sequence');
    }

    public function cabinets()
    {
        return $this->hasMany(Cabinet::class);
    }
}
