<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleExpense extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $guarded = ['id', 'business_id'];

    protected $appends = ['source'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function source(): Attribute
    {
        return Attribute::get(fn () => $this->maintenance_record_id
            ? "Maintenance record #{$this->maintenance_record_id}"
            : ($this->fuel_log_id ? "Fuel log #{$this->fuel_log_id}" : 'Manual'));
    }
}
