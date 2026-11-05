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

    /** The rounds it went on / is put on (through ProjectDeliveryVehicle). */
    public function deliveries()
    {
        return $this->belongsToMany(ProjectDelivery::class, 'project_delivery_vehicles');
    }

    /** The kinds of vehicle PM may ask for: those of the vehicles in use (plus $keep, a round's kinds whose vehicles are gone). */
    public static function types(string|array|null $keep = null): array
    {
        return self::where('is_active', true)->whereNotNull('type')->where('type', '!=', '')->distinct()->orderBy('type')->pluck('type')
            ->merge((array) $keep)->filter()->unique()->sort()->values()->all();
    }

    /** "6 ล้อเครน · 3กข-1234" */
    public function label(): string
    {
        return $this->name.($this->plate ? ' · '.$this->plate : '');
    }
}
