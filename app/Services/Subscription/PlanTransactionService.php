<?php

namespace App\Services\Subscription;

use App\Models\PaymentMethod;
use App\Models\PlanTransaction;
use App\Models\SubscriptionPlanOffering;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PlanTransactionService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions
    ) {}

    public function create(User $user, array $data): PlanTransaction
    {
        $offering = SubscriptionPlanOffering::whereKey($data['subscription_plan_offering_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $method = PaymentMethod::findOrFail($data['payment_method_id']);

        return $this->subscriptions->createSubscriptionTransaction(
            $user->business,
            $offering,
            $method,
            $user->id
        );
    }

    public function preview(User $user, array $data): array
    {
        $offering = SubscriptionPlanOffering::whereKey($data['subscription_plan_offering_id'])
            ->where('is_active', true)
            ->firstOrFail();

        return $this->subscriptions->previewPlanChange($user->business, $offering);
    }

    public function submitPayment(
        User $user,
        PlanTransaction $transaction,
        array $data,
        ?UploadedFile $proof
    ): PlanTransaction {
        $this->authorizeOwnerTransaction($user, $transaction);

        if ($transaction->payment_status === 'paid') {
            throw ValidationException::withMessages([
                'transaction' => 'This transaction has already been paid.',
            ]);
        }

        return DB::transaction(function () use ($transaction, $data, $proof) {
            $transaction = PlanTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $proofPath = $transaction->payment_proof_path;

            if ($proof) {
                if ($proofPath) {
                    Storage::disk('local')->delete($proofPath);
                }

                $proofPath = $proof->store("payment-proofs/{$transaction->id}", 'local');
            }

            $transaction->update([
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_proof_path' => $proofPath,
                'status' => 'processing',
                'payment_status' => 'pending_verification',
                'payment_submitted_at' => now(),
                'payment_verified_at' => null,
                'payment_rejection_reason' => null,
            ]);

            return $transaction->fresh();
        });
    }

    public function approvePayment(PlanTransaction $transaction): PlanTransaction
    {
        $this->subscriptions->approveSubscriptionTransaction($transaction);

        return $transaction->fresh();
    }

    public function rejectPayment(PlanTransaction $transaction, string $reason): PlanTransaction
    {
        if ($transaction->payment_status !== 'pending_verification') {
            throw ValidationException::withMessages([
                'transaction' => 'Only payments pending verification can be reviewed.',
            ]);
        }

        return DB::transaction(function () use ($transaction, $reason) {
            $transaction = PlanTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->payment_status !== 'pending_verification') {
                throw ValidationException::withMessages([
                    'transaction' => 'Only payments pending verification can be reviewed.',
                ]);
            }

            $transaction->update([
                'payment_status' => 'rejected',
                'status' => 'failed',
                'payment_verified_at' => null,
                'payment_rejection_reason' => $reason,
            ]);

            return $transaction->fresh();
        });
    }

    private function authorizeOwnerTransaction(User $user, PlanTransaction $transaction): void
    {
        abort_unless($transaction->business_id === $user->business_id, 403);
    }
}
