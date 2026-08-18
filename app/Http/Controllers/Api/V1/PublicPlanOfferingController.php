<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Subscription\SubscriptionPlanService;

class PublicPlanOfferingController extends ApiController
{
    public function __invoke(SubscriptionPlanService $plans)
    {
        return $this->ok($plans->getPublicActiveOfferings());
    }
}
