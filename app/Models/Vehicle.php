<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['status' => VehicleStatus::class, 'acquisition_date' => 'date', 'acquisition_cost' => 'decimal:2'];
    }

    public function mileageLogs()
    {
        return $this->hasMany(MileageLog::class);
    }

    public function schedules()
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function expenses()
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function documents()
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function issues()
    {
        return $this->hasMany(VehicleIssue::class);
    }

    public function assignments()
    {
        return $this->hasMany(VehicleAssignment::class);
    }
}
