<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class MaintenanceSchedule extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['last_service_date' => 'date', 'next_service_date' => 'date'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
