<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ReminderDelivery extends Model
{
    use BelongsToBusiness;

    public $timestamps = false;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
