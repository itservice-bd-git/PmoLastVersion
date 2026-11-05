<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings > รถขนส่ง: a company vehicle a delivery round goes on (ProjectDelivery). */
class Vehicle extends Model
{
    protected $fillable = ['name', 'type', 'plate', 'capacity', 'equipment', 'driver_name', 'driver_phone', 'is_active', 'sequence'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function deliveries()
    {
        return $this->hasMany(ProjectDelivery::class);
    }

    /** The kinds of vehicle PM may ask for: those of the vehicles in use (plus $keep, a round's kind whose vehicles are gone). */
    public static function types(?string $keep = null): array
    {
        return self::where('is_active', true)->whereNotNull('type')->where('type', '!=', '')->distinct()->orderBy('type')->pluck('type')
            ->push($keep)->filter()->unique()->sort()->values()->all();
    }

    /** "6 ล้อเครน · 3กข-1234" */
    public function label(): string
    {
        return $this->name.($this->plate ? ' · '.$this->plate : '');
    }
}
