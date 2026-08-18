<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Payment\StorePaymentMethodRequest;
use App\Http\Requests\Payment\UpdatePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentMethodService;
use Illuminate\Support\Facades\Storage;

class PaymentMethodController extends ApiController
{
    public function __construct(private readonly PaymentMethodService $paymentMethodService) {}

    public function index()
    {
        return $this->ok(PaymentMethod::latest()->get());
    }

    public function store(StorePaymentMethodRequest $request)
    {
        $paymentMethod = $this->paymentMethodService->create($request->validated(), $request->file('qr'));

        return $this->ok($paymentMethod, 'Payment method created.', 201);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod)
    {
        $paymentMethod = $this->paymentMethodService->update($paymentMethod, $request->validated(), $request->file('qr'));

        return $this->ok($paymentMethod, 'Payment method updated.');
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        $this->paymentMethodService->delete($paymentMethod);

        return $this->ok(null, 'Payment method deleted.');
    }

    public function qr(PaymentMethod $paymentMethod)
    {
        abort_unless($paymentMethod->qr_path && Storage::disk('local')->exists($paymentMethod->qr_path), 404);

        return Storage::disk('local')->response($paymentMethod->qr_path, $paymentMethod->qr_name);
    }
}
