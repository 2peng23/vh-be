<?php

namespace App\Services\Subscription;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PlanTransaction;
use App\Models\ScheduledSubscriptionChange;
use App\Models\SubscriptionPlanOffering;
use App\Support\SubscriptionPlans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public const PLAN_LEVELS = [
        'trial' => 0,
        'starter' => 1,
        'business' => 2,
        'enterprise' => 3,
    ];

    public function determineTransactionType(Business $business, string $toPlan): string
    {
        $fromPlan = $this->normalizePlan($business->subscription_plan);
        $toPlan = $this->normalizePlan($toPlan);

        if ($toPlan === 'trial') {
            throw ValidationException::withMessages([
                'subscription_plan_offering_id' => 'Trial cannot be purchased as a paid subscription.',
            ]);
        }

        if ($fromPlan === $toPlan && $fromPlan !== 'trial') {
            return 'renewal';
        }

        if (self::PLAN_LEVELS[$toPlan] > self::PLAN_LEVELS[$fromPlan]) {
            return $fromPlan === 'trial' ? 'purchase' : 'upgrade';
        }

        if (self::PLAN_LEVELS[$toPlan] < self::PLAN_LEVELS[$fromPlan]) {
            return 'downgrade';
        }

        return 'purchase';
    }

    public function calculateRemainingDays(Business $business): int
    {
        if (! $business->plan_ends_at) {
            return 0;
        }

        $today = $this->today($business);
        $endsAt = CarbonImmutable::parse($business->plan_ends_at, $business->timezone ?: config('app.timezone'))->startOfDay();

        if ($endsAt->lessThanOrEqualTo($today)) {
            return 0;
        }

        return (int) $today->diffInDays($endsAt);
    }

    public function previewPlanChange(Business $business, SubscriptionPlanOffering $offering): array
    {
        $type = $this->determineTransactionType($business, $offering->plan);
        $period = $this->periodFor($business, $offering, $type);
        $credit = $type === 'upgrade'
            ? $this->calculateProrationCredit($business)
            : 0;
        $originalAmount = $this->moneyToCents($offering->price);
        $amountDue = max(0, $originalAmount - $credit);

        return [
            'type' => $type,
            'from_plan' => $business->subscription_plan,
            'to_plan' => $offering->plan,
            'plan' => $offering->plan,
            'remaining_days' => $this->calculateRemainingDays($business),
            'original_price' => $this->centsToMoney($originalAmount),
            'credit_amount' => $this->centsToMoney($credit),
            'amount_due' => $this->centsToMoney($amountDue),
            'current_ends_at' => $business->plan_ends_at?->toDateString(),
            'new_starts_at' => $period['starts_at']->toDateString(),
            'new_ends_at' => $period['ends_at']->toDateString(),
            'effective' => match ($type) {
                'downgrade' => 'scheduled_after_current_plan',
                'renewal' => 'extends_current_plan',
                default => 'immediately_after_payment_approval',
            },
            'effective_at' => $type === 'downgrade' ? $period['starts_at']->toDateString() : null,
            'vehicle_limit' => (int) $offering->vehicle_limit,
            'duration_months' => (int) $offering->duration_months,
        ];
    }

    public function createSubscriptionTransaction(
        Business $business,
        SubscriptionPlanOffering $offering,
        PaymentMethod $method,
        int $createdBy
    ): PlanTransaction {
        return DB::transaction(function () use ($business, $offering, $method, $createdBy) {
            $business = Business::lockForUpdate()->findOrFail($business->id);
            $preview = $this->previewPlanChange($business, $offering);

            $duplicate = PlanTransaction::query()
                ->where('business_id', $business->id)
                ->where('plan', $offering->plan)
                ->where('transaction_type', $preview['type'])
                ->whereIn('payment_status', ['not_paid', 'pending_verification'])
                ->where('status', 'processing')
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'subscription_plan_offering_id' => 'You already have a pending transaction for this plan change.',
                ]);
            }

            return PlanTransaction::create([
                'business_id' => $business->id,
                'subscription_plan_offering_id' => $offering->id,
                'created_by' => $createdBy,
                'transaction_type' => $preview['type'],
                'from_plan' => $preview['from_plan'],
                'plan' => $offering->plan,
                'duration_months' => $offering->duration_months,
                'original_amount' => $preview['original_price'],
                'credit_amount' => $preview['credit_amount'],
                'amount' => $preview['amount_due'],
                'currency' => 'PHP',
                'payment_method_id' => $method->id,
                'payment_method' => $method->name,
                'reference' => $this->transactionReference($business),
                'paid_at' => null,
                'starts_at' => $preview['new_starts_at'],
                'ends_at' => $preview['new_ends_at'],
                'status' => 'processing',
                'payment_status' => 'not_paid',
            ]);
        });
    }

    public function approveSubscriptionTransaction(PlanTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction = PlanTransaction::query()
                ->with('business')
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($transaction->status === 'completed' || $transaction->payment_status === 'paid') {
                throw ValidationException::withMessages(['transaction' => 'This transaction has already been completed.']);
            }

            if ($transaction->payment_status !== 'pending_verification') {
                throw ValidationException::withMessages(['transaction' => 'Only payments pending verification can be approved.']);
            }

            if (in_array($transaction->transaction_type, ['purchase', 'upgrade'], true)) {
                $startsAt = $this->today($transaction->business);

                $transaction->starts_at = $startsAt->toDateString();
                $transaction->ends_at = $this->endDate($startsAt, (int) $transaction->duration_months)->toDateString();
            }

            $transaction->update([
                'payment_status' => 'paid',
                'status' => 'completed',
                'paid_at' => $this->today($transaction->business)->toDateString(),
                'payment_verified_at' => now(),
                'payment_rejection_reason' => null,
                'starts_at' => $transaction->starts_at,
                'ends_at' => $transaction->ends_at,
            ]);

            match ($transaction->transaction_type) {
                'renewal' => $this->activateRenewal($transaction),
                'downgrade' => $this->scheduleDowngrade($transaction),
                default => $this->activateUpgrade($transaction),
            };
        });
    }

    public function activateUpgrade(PlanTransaction $transaction): void
    {
        $transaction->business()->update([
            'subscription_plan' => $transaction->plan,
            'subscription_status' => 'active',
            'plan_started_at' => $transaction->starts_at,
            'plan_ends_at' => $transaction->ends_at,
        ]);
    }

    public function activateRenewal(PlanTransaction $transaction): void
    {
        $business = $transaction->business;
        $today = $this->today($business);
        $currentEndsAt = $business->plan_ends_at
            ? CarbonImmutable::parse($business->plan_ends_at, $business->timezone ?: config('app.timezone'))->startOfDay()
            : null;

        $transaction->business()->update([
            'subscription_plan' => $transaction->plan,
            'subscription_status' => 'active',
            'plan_started_at' => $currentEndsAt && $currentEndsAt->greaterThanOrEqualTo($today)
                ? $business->plan_started_at
                : $transaction->starts_at,
            'plan_ends_at' => $transaction->ends_at,
        ]);
    }

    public function scheduleDowngrade(PlanTransaction $transaction): void
    {
        ScheduledSubscriptionChange::updateOrCreate(
            ['plan_transaction_id' => $transaction->id],
            [
                'business_id' => $transaction->business_id,
                'from_plan' => $transaction->from_plan ?: $transaction->business->subscription_plan,
                'to_plan' => $transaction->plan,
                'starts_at' => $transaction->starts_at,
                'ends_at' => $transaction->ends_at,
                'status' => 'scheduled',
            ]
        );
    }

    public function applyScheduledChange(ScheduledSubscriptionChange $change): bool
    {
        return DB::transaction(function () use ($change) {
            $change = ScheduledSubscriptionChange::query()->lockForUpdate()->findOrFail($change->id);

            if ($change->status !== 'scheduled') {
                return false;
            }

            $change->business()->update([
                'subscription_plan' => $change->to_plan,
                'subscription_status' => 'active',
                'plan_started_at' => $change->starts_at,
                'plan_ends_at' => $change->ends_at,
            ]);

            $change->update(['status' => 'applied']);

            return true;
        });
    }

    public function resolveVehicleLimit(Business $business): int
    {
        if ($business->vehicle_limit_override !== null) {
            return (int) $business->vehicle_limit_override;
        }

        $limit = SubscriptionPlanOffering::query()
            ->where('plan', $this->normalizePlan($business->subscription_plan))
            ->orderBy('duration_months')
            ->value('vehicle_limit');

        $plan = $this->normalizePlan($business->subscription_plan);

        return $limit !== null
            ? (int) $limit
            : SubscriptionPlans::VEHICLE_LIMITS[$plan];
    }

    public function calculateProrationCredit(Business $business): int
    {
        if ($this->normalizePlan($business->subscription_plan) === 'trial') {
            return 0;
        }

        $active = $this->activePaidTransaction($business);
        if (! $active || ! $active->starts_at || ! $active->ends_at) {
            return 0;
        }

        $today = $this->today($business);
        $startsAt = CarbonImmutable::parse($active->starts_at)->startOfDay();
        $endsAt = CarbonImmutable::parse($active->ends_at)->startOfDay();

        if ($endsAt->lessThanOrEqualTo($today) || $endsAt->lessThanOrEqualTo($startsAt)) {
            return 0;
        }

        $totalDays = max(1, (int) $startsAt->diffInDays($endsAt));
        $remainingDays = max(0, (int) $today->diffInDays($endsAt));

        return intdiv($this->moneyToCents($active->amount) * $remainingDays, $totalDays);
    }

    private function activePaidTransaction(Business $business): ?PlanTransaction
    {
        $today = $this->today($business)->toDateString();

        return PlanTransaction::query()
            ->where('business_id', $business->id)
            ->where('plan', $business->subscription_plan)
            ->where('payment_status', 'paid')
            ->where('status', 'completed')
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->latest('paid_at')
            ->latest('id')
            ->first();
    }

    private function periodFor(Business $business, SubscriptionPlanOffering $offering, string $type): array
    {
        $today = $this->today($business);
        $currentEndsAt = $business->plan_ends_at
            ? CarbonImmutable::parse($business->plan_ends_at, $business->timezone ?: config('app.timezone'))->startOfDay()
            : null;

        $startsAt = match ($type) {
            'renewal', 'downgrade' => $currentEndsAt && $currentEndsAt->greaterThanOrEqualTo($today)
                ? $currentEndsAt->addDay()
                : $today,
            default => $today,
        };

        return [
            'starts_at' => $startsAt,
            'ends_at' => $this->endDate($startsAt, (int) $offering->duration_months),
        ];
    }

    private function endDate(CarbonImmutable $startsAt, int $durationMonths): CarbonImmutable
    {
        return $startsAt->addMonthsNoOverflow(max(1, $durationMonths))->subDay();
    }

    private function today(Business $business): CarbonImmutable
    {
        return CarbonImmutable::now($business->timezone ?: config('app.timezone'))->startOfDay();
    }

    private function normalizePlan(?string $plan): string
    {
        return array_key_exists((string) $plan, self::PLAN_LEVELS) ? (string) $plan : 'trial';
    }

    private function moneyToCents(int|float|string|null $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        $value = str_replace(',', '', $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '00');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function transactionReference(Business $business): string
    {
        $prefix = Str::upper(Str::slug($business->name, '-')) ?: 'BUSINESS';

        do {
            $reference = "{$prefix}-".now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (PlanTransaction::where('reference', $reference)->exists());

        return $reference;
    }
}
