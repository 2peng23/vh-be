<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceRecord extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $guarded = ['id', 'business_id', 'total_cost'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'next_service_date' => 'date', 'total_cost' => 'decimal:2'];
    }

    public function parts()
    {
        return $this->hasMany(MaintenancePart::class);
    }

    public function maintenanceSchedule()
    {
        return $this->belongsTo(MaintenanceSchedule::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
