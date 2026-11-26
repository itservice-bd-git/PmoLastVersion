<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ลูกค้า: one row per customer (2026_11_17_000002), which projects point at with customer_id. The project form still
 * takes the customer's name and phone as text (customer_name / customer_phone stay as they were); Project links the
 * customer of that name, making it the first time the name is seen (Project::linkCustomer).
 */
class Customer extends Model
{
    protected $fillable = ['name', 'phone'];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
