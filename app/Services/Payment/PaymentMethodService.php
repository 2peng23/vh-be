<?php

namespace App\Services\Payment;

use App\Models\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PaymentMethodService
{
    public function create(array $validated, ?UploadedFile $qrFile = null): PaymentMethod
    {
        return PaymentMethod::create($this->attributes($validated, $qrFile));
    }

    public function update(PaymentMethod $paymentMethod, array $validated, ?UploadedFile $qrFile = null): PaymentMethod
    {
        $attributes = $this->attributes($validated, $qrFile, $paymentMethod);

        if (($validated['remove_qr'] ?? false) && $qrFile === null) {
            $this->deleteQr($paymentMethod);
            $attributes['qr_path'] = null;
            $attributes['qr_name'] = null;
        }

        $paymentMethod->update($attributes);

        return $paymentMethod->fresh();
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $this->deleteQr($paymentMethod);
        $paymentMethod->delete();
    }

    private function attributes(array $validated, ?UploadedFile $qrFile = null, ?PaymentMethod $paymentMethod = null): array
    {
        $attributes = [
            'name' => $validated['name'],
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
        ];

        if ($qrFile !== null) {
            if ($paymentMethod !== null) {
                $this->deleteQr($paymentMethod);
            }

            $attributes['qr_path'] = $qrFile->store('payment-methods', 'local');
            $attributes['qr_name'] = $qrFile->getClientOriginalName();
        }

        return $attributes;
    }

    private function deleteQr(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->qr_path) {
            Storage::disk('local')->delete($paymentMethod->qr_path);
        }
    }
}
