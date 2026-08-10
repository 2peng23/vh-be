<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleIssue extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
