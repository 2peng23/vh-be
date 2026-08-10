<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MileageLog extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'is_override' => 'boolean'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
