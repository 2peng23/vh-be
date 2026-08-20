<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Subscription\PreviewSubscriptionChangeRequest;
use App\Http\Requests\Subscription\StorePlanTransactionRequest;
use App\Http\Requests\Subscription\SubmitPaymentRequest;
use App\Models\PlanTransaction;
use App\Services\Subscription\PlanTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlanTransactionController extends ApiController
{
    public function __construct(
        private readonly PlanTransactionService $transactions
    ) {}

    /** Create a processing transaction from an active configured offering. */
    public function store(StorePlanTransactionRequest $request)
    {
        $transaction = $this->transactions->create($request->user(), $request->validated());

        return $this->ok($transaction->load('selectedPaymentMethod:id,name,account_name,account_number,qr_path'), 'Plan transaction created.', 201);
    }

    /** Preview a plan change without creating a transaction. */
    public function preview(PreviewSubscriptionChangeRequest $request)
    {
        return $this->ok($this->transactions->preview($request->user(), $request->validated()));
    }

    /** Return plan transactions belonging only to the authenticated owner's business. */
    public function index(Request $request)
    {
        $this->ownerOnly($request);
        $this->transactions->expireOldUnpaidTransactions();

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
        $this->transactions->expireOldUnpaidTransactions();

        abort_unless($planTransaction->business_id === $request->user()->business_id, 404);

        return $this->ok($planTransaction->load('selectedPaymentMethod:id,name,account_name,account_number,qr_path'));
    }

    /** Let the business owner declare that payment has been sent for this transaction. */
    public function submitPayment(
        SubmitPaymentRequest $request,
        PlanTransaction $planTransaction
    ) {
        $transaction = $this->transactions->submitPayment(
            $request->user(),
            $planTransaction,
            $request->validated(),
            $request->file('proof')
        );

        return $this->ok($transaction, 'Payment submitted successfully and is awaiting verification.');
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
