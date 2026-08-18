<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $isPlanPurchaseRequest = $request->isMethod('GET')
            && ($request->is('api/v1/plan-offerings') || $request->is('api/v1/payment-methods'));

        $user = $request->user();
        $currentUserStatus = $user ? User::whereKey($user->id)->value('status') : null;
        if ($user?->role->value === 'staff' && $currentUserStatus !== 'active') {
            return response()->json([
                'success' => false,
                'code' => 'STAFF_INACTIVE',
                'message' => 'Your staff account is inactive. Please contact your business owner.',
            ], 403);
        }
        if ($request->is('api/v1/support/*') || $request->is('api/v1/superadmin/support/*') || $request->is('api/v1/plan-transactions*') || $request->is('api/v1/subscription/preview') || $isPlanPurchaseRequest) {
            return $next($request);
        }
        if ($user?->business_id) {
            $user->business?->syncPlanStatus();
        }
        if ($user?->business?->isInactive()) {
            return response()->json([
                'success' => false,
                'code' => 'BUSINESS_INACTIVE',
                'message' => 'This business account has been disabled. Please contact support for assistance.',
            ], 403);
        }
        if ($user?->business_id && $user->business?->planHasEnded()) {
            return response()->json([
                'success' => false,
                'code' => 'PLAN_ENDED',
                'message' => 'Your plan has ended. Please contact support to reactivate your account.',
            ], 403);
        }

        return $next($request);
    }
}
