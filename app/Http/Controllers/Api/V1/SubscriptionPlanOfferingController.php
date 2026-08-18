<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Subscription\StoreSubscriptionPlanOfferingRequest;
use App\Http\Requests\Subscription\UpdateSubscriptionPlanOfferingRequest;
use App\Models\SubscriptionPlanOffering;
use App\Services\Subscription\SubscriptionPlanService;
use Illuminate\Http\Request;

class SubscriptionPlanOfferingController extends ApiController
{
    public function __construct(private readonly SubscriptionPlanService $subscriptionPlanService) {}

    public function index(Request $request)
    {
        return $this->ok($this->subscriptionPlanService->listForUser($request->user()));
    }

    public function store(StoreSubscriptionPlanOfferingRequest $request)
    {
        $offering = $this->subscriptionPlanService->create($request->validated());

        return $this->ok($offering, 'Plan offering created.', 201);
    }

    public function update(UpdateSubscriptionPlanOfferingRequest $request, SubscriptionPlanOffering $plan_offering)
    {
        $offering = $this->subscriptionPlanService->update($plan_offering, $request->validated());

        return $this->ok($offering, 'Plan offering updated.');
    }
}
