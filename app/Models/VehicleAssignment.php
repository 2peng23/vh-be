<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class VehicleAssignment extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
