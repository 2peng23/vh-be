<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleDocument extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiration_date' => 'date'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
