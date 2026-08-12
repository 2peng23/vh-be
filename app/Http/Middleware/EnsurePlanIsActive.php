<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/support/*') || $request->is('api/v1/superadmin/support/*')) {
            return $next($request);
        }
        $user = $request->user();
        if ($user?->business_id) {
            $user->business?->syncPlanStatus();
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
