<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    public function initializeBelongsToBusiness(): void
    {
        $this->guarded = array_values(array_diff($this->guarded, ['business_id']));
    }

    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $q) {
            if (auth()->check()) {
                $q->where($q->getModel()->qualifyColumn('business_id'), auth()->user()->business_id);
            }
        });
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->business_id = auth()->user()->business_id;
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
