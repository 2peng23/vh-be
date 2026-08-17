<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanTransaction extends Model
{
    protected $guarded = ['id'];

    protected $fillable = [
        'business_id',
        'subscription_plan_offering_id',
        'created_by',
        'plan',
        'duration_months',
        'amount',
        'currency',
        'payment_method_id',
        'payment_method',
        'reference',
        'status',
        'payment_status',
        'payment_reference',
        'payment_proof_path',
        'payment_submitted_at',
        'payment_verified_at',
        'payment_rejection_reason',
        'paid_marked_at',
        'paid_at',
        'starts_at',
        'ends_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'amount' => 'decimal:2',

            'payment_submitted_at' => 'datetime',
            'payment_verified_at' => 'datetime',
            'paid_marked_at' => 'datetime',

            'paid_at' => 'date',
            'starts_at' => 'date',
            'ends_at' => 'date',
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
