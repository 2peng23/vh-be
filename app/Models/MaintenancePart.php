<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class MaintenancePart extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id', 'total_cost'];
}
