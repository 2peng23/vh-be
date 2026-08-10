<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['license_expiration' => 'date', 'date_hired' => 'date'];
    }
}
