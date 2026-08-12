<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanOffering extends Model
{
    protected $guarded = ['id'];

    /** Cast configured prices and availability to stable API values. */
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
