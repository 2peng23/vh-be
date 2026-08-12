<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanTransaction extends Model
{
    protected $guarded = ['id'];

    /** Cast monetary and calendar values for consistent API serialization. */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'paid_marked_at' => 'datetime',
        ];
    }

    /** Return the business that purchased the plan. */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Return the administrator who recorded the transaction. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Return the configured payment method selected for this purchase. */
    public function selectedPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** Return the configurable offering selected when the purchase was created. */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanOffering::class, 'subscription_plan_offering_id');
    }
}
