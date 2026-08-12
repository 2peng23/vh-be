<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PaymentMethodController extends ApiController
{
    /** Return all configured payment methods in display order. */
    public function index()
    {
        return $this->ok(PaymentMethod::latest()->get());
    }

    /** Create a payment method with an optional private QR image. */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $method = PaymentMethod::create($this->attributes($request, $data));

        return $this->ok($method, 'Payment method created.', 201);
    }

    /** Update payment details and replace the QR image when supplied. */
    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $data = $this->validated($request, $paymentMethod);
        $attributes = $this->attributes($request, $data, $paymentMethod);
        if ($request->boolean('remove_qr') && ! $request->hasFile('qr')) {
            $this->deleteQr($paymentMethod);
            $attributes['qr_path'] = null;
            $attributes['qr_name'] = null;
        }
        $paymentMethod->update($attributes);

        return $this->ok($paymentMethod->fresh(), 'Payment method updated.');
    }

    /** Delete a payment method and its private QR image. */
    public function destroy(PaymentMethod $paymentMethod)
    {
        $this->deleteQr($paymentMethod);
        $paymentMethod->delete();

        return $this->ok(null, 'Payment method deleted.');
    }

    /** Stream a QR image only to an authenticated Super Admin. */
    public function qr(PaymentMethod $paymentMethod)
    {
        abort_unless($paymentMethod->qr_path && Storage::disk('local')->exists($paymentMethod->qr_path), 404);

        return Storage::disk('local')->response($paymentMethod->qr_path, $paymentMethod->qr_name);
    }

    /** Validate editable payment method fields and QR file constraints. */
    private function validated(Request $request, ?PaymentMethod $paymentMethod = null): array
    {
        return $request->validate([
            'name' => ['required', Rule::in(['Maya', 'GCash', 'BDO', 'Chinabank', 'UnionBank']), Rule::unique('payment_methods', 'name')->ignore($paymentMethod?->id)],
            'account_name' => 'required|string|max:150',
            'account_number' => 'required|string|max:150',
            'qr' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_qr' => 'nullable|boolean',
        ]);
    }

    /** Build persistence attributes and safely replace an existing QR image. */
    private function attributes(Request $request, array $data, ?PaymentMethod $paymentMethod = null): array
    {
        $attributes = [
            'name' => $data['name'],
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
        ];
        if ($request->hasFile('qr')) {
            if ($paymentMethod) $this->deleteQr($paymentMethod);
            $attributes['qr_path'] = $request->file('qr')->store('payment-methods', 'local');
            $attributes['qr_name'] = $request->file('qr')->getClientOriginalName();
        }

        return $attributes;
    }

    /** Remove a previously stored QR image when it exists. */
    private function deleteQr(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->qr_path) Storage::disk('local')->delete($paymentMethod->qr_path);
    }
}
