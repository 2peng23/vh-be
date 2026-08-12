<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PlanTransaction;
use App\Models\PaymentMethod;
use App\Models\SubscriptionPlanOffering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlanTransactionController extends ApiController
{
    /** Create a processing transaction from an active configured offering. */
    public function store(Request $request)
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'subscription_plan_offering_id' => 'required|integer|exists:subscription_plan_offerings,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
        ]);
        $offering = SubscriptionPlanOffering::whereKey($data['subscription_plan_offering_id'])->where('is_active', true)->firstOrFail();
        $method = PaymentMethod::findOrFail($data['payment_method_id']);
        $business = $request->user()->business;

        $transaction = DB::transaction(function () use ($request, $business, $offering, $method) {
            do {
                $reference = (Str::upper(Str::slug($business->name)) ?: 'BUSINESS').'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            } while (PlanTransaction::where('reference', $reference)->exists());

            return PlanTransaction::create([
                'business_id' => $business->id,
                'subscription_plan_offering_id' => $offering->id,
                'created_by' => $request->user()->id,
                'plan' => $offering->plan,
                'duration_months' => $offering->duration_months,
                'amount' => $offering->price,
                'currency' => 'PHP',
                'payment_method_id' => $method->id,
                'payment_method' => $method->name,
                'reference' => $reference,
                'paid_at' => now()->toDateString(),
                'starts_at' => null,
                'ends_at' => null,
                'status' => 'processing',
                'payment_status' => 'not_paid',
            ]);
        });

        return $this->ok($transaction->load('selectedPaymentMethod:id,name,account_name,account_number,qr_path'), 'Plan transaction created.', 201);
    }

    /** Return plan transactions belonging only to the authenticated owner's business. */
    public function index(Request $request)
    {
        $this->ownerOnly($request);
        $query = PlanTransaction::query()
            ->with('selectedPaymentMethod:id,name,account_name,account_number,qr_path')
            ->where('business_id', $request->user()->business_id)
            ->latest('paid_at')
            ->latest('id');

        return $this->paginated($query->paginate(min((int) $request->input('per_page', 20), 100)));
    }

    /** Return one tenant-scoped transaction for its business owner. */
    public function show(Request $request, PlanTransaction $planTransaction)
    {
        $this->ownerOnly($request);
        abort_unless($planTransaction->business_id === $request->user()->business_id, 404);

        return $this->ok($planTransaction->load('selectedPaymentMethod:id,name,account_name,account_number,qr_path'));
    }

    /** Let the business owner declare that payment has been sent for this transaction. */
    public function markAsPaid(Request $request, PlanTransaction $planTransaction)
    {
        $this->ownerOnly($request);
        abort_unless($planTransaction->business_id === $request->user()->business_id, 404);

        if ($planTransaction->payment_status !== 'paid') {
            $planTransaction->update([
                'payment_status' => 'paid',
                'paid_marked_at' => now(),
                'paid_at' => now()->toDateString(),
            ]);
        }

        return $this->ok(
            $planTransaction->fresh()->load('selectedPaymentMethod:id,name,account_name,account_number,qr_path'),
            'Transaction marked as paid.'
        );
    }

    /** Stream the selected payment method QR only to the transaction's business owner. */
    public function paymentQr(Request $request, PlanTransaction $planTransaction)
    {
        $this->ownerOnly($request);
        abort_unless($planTransaction->business_id === $request->user()->business_id, 404);
        $method = $planTransaction->selectedPaymentMethod;
        abort_unless($method?->qr_path && Storage::disk('local')->exists($method->qr_path), 404);

        return Storage::disk('local')->response($method->qr_path, $method->qr_name);
    }

    /** Restrict tenant transaction history to the business owner. */
    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()->business_id && $request->user()->role->value === 'owner', 403);
    }
}
