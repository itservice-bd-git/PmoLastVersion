<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One vehicle of a delivery round (ProjectDelivery), put on it by the delivery department: a company vehicle (vehicle_id)
 * or an outside carrier's truck (label only), with its driver and the people who go with it.
 */
class ProjectDeliveryVehicle extends Model
{
    protected $fillable = ['project_delivery_id', 'vehicle_id', 'label', 'driver_name', 'crew', 'sequence'];

    protected function casts(): array
    {
        return ['crew' => 'array'];
    }

    public function delivery()
    {
        return $this->belongsTo(ProjectDelivery::class, 'project_delivery_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** "10 ล้อ · 70-1234", an outside truck's label, or "รถ" */
    public function name(): string
    {
        return $this->vehicle?->label() ?? ($this->label ?: 'รถ');
    }

    /** The people on it: the driver first. */
    public function people(): array
    {
        return array_values(array_filter([$this->driver_name, ...($this->crew ?? [])]));
    }
}
