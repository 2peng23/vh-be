<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MileageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PlanTransactionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\SubscriptionPlanOfferingController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleResourceController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return response()->json(['success' => true, 'message' => 'Email verified.']);
    })->middleware(['auth:sanctum', 'signed'])->name('verification.verify');
    Route::prefix('auth')->middleware('throttle:login')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', function (Request $r) {
            $r->validate(['email' => 'required|email']);

            return response()->json(['success' => true, 'message' => Password::sendResetLink($r->only('email'))]);
        });
    });
    Route::prefix('guest-support')->middleware('throttle:60,1')->group(function () {
        Route::post('conversations', [SupportController::class, 'createGuestConversation']);
        Route::get('messages', [SupportController::class, 'guestMessages']);
        Route::post('messages', [SupportController::class, 'guestSend']);
        Route::get('attachments/{supportMessage}', [SupportController::class, 'guestDownload']);
    });
    // Keep restricted account screens synchronized without granting access to tenant data.
    Route::get('access-status', [AuthController::class, 'accessStatus'])->middleware('auth:sanctum');
    Route::middleware(['auth:sanctum', 'plan.active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::put('me/password', [AuthController::class, 'changePassword']);
        Route::prefix('superadmin')->middleware('superadmin')->group(function () {
            Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
            Route::get('businesses', [SuperAdminController::class, 'businesses']);
            Route::post('owners', [SuperAdminController::class, 'storeOwner']);
            Route::put('businesses/{business}', [SuperAdminController::class, 'updateBusiness']);
            Route::get('transactions', [SuperAdminController::class, 'transactions']);
            Route::post('transactions', [SuperAdminController::class, 'storeTransaction']);
            Route::put('transactions/{planTransaction}/status', [SuperAdminController::class, 'updateTransactionStatus']);
            Route::apiResource('plan-offerings', SubscriptionPlanOfferingController::class)->only(['index', 'store', 'update']);
            Route::apiResource('payment-methods', PaymentMethodController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::get('payment-methods/{paymentMethod}/qr', [PaymentMethodController::class, 'qr']);
            Route::get('users', [SuperAdminController::class, 'users']);
            Route::put('users/{user}', [SuperAdminController::class, 'updateUser']);
            Route::get('permissions', [SuperAdminController::class, 'permissions']);
            Route::get('permissions/users', [SuperAdminController::class, 'permissionUsers']);
            Route::get('permissions/users/{user}', [SuperAdminController::class, 'permissionUser']);
            Route::put('permissions/apply-to-role', [SuperAdminController::class, 'applyPermissionsToRole']);
            Route::put('permissions/users/{user}', [SuperAdminController::class, 'updateUserPermissions']);
            Route::post('users/{user}/impersonate', [SuperAdminController::class, 'impersonate']);
            Route::get('support/conversations', [SupportController::class, 'conversations']);
            Route::get('support/businesses/{business}', [SupportController::class, 'adminMessages']);
            Route::post('support/businesses/{business}', [SupportController::class, 'adminSend']);
            Route::get('support/guests/{guestSupportConversation}', [SupportController::class, 'adminGuestMessages']);
            Route::post('support/guests/{guestSupportConversation}', [SupportController::class, 'adminGuestSend']);
            Route::get('support/templates', [SupportController::class, 'templates']);
            Route::post('support/templates', [SupportController::class, 'storeTemplate']);
            Route::put('support/templates/{supportTemplate}', [SupportController::class, 'updateTemplate']);
        });
        Route::get('support/messages', [SupportController::class, 'messages']);
        Route::post('support/messages', [SupportController::class, 'send']);
        Route::get('support/unread-count', [SupportController::class, 'unreadCount']);
        Route::get('support/attachments/{supportMessage}', [SupportController::class, 'download']);
        Route::get('plan-transactions', [PlanTransactionController::class, 'index']);
        Route::post('plan-transactions', [PlanTransactionController::class, 'store']);
        Route::get('plan-offerings', [SubscriptionPlanOfferingController::class, 'index']);
        Route::get('payment-methods', [PaymentMethodController::class, 'index']);
        Route::get('plan-transactions/{planTransaction}', [PlanTransactionController::class, 'show']);
        Route::put('plan-transactions/{planTransaction}/mark-paid', [PlanTransactionController::class, 'markAsPaid']);
        Route::get('plan-transactions/{planTransaction}/payment-qr', [PlanTransactionController::class, 'paymentQr']);
        Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view');
        Route::post('staff', [StaffController::class, 'store'])->middleware('permission:staff.create');
        Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.update');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('permission:staff.delete');
        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');
        Route::get('audit-logs/options', [AuditLogController::class, 'options'])->middleware('permission:audit.view');
        Route::get('audit-logs/{auditLog}/document-file', [AuditLogController::class, 'documentFile'])->middleware('permission:audit.view');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
        Route::get('assignees', [VehicleResourceController::class, 'assignees']);
        Route::get('maintenance', [OperationsController::class, 'maintenance'])->middleware('permission:maintenance.view');
        Route::get('issues', [OperationsController::class, 'issues'])->middleware('permission:issues.view');
        Route::get('documents', [OperationsController::class, 'documents'])->middleware('permission:documents.view');
        Route::get('documents/{document}/download', [VehicleResourceController::class, 'download'])->middleware('permission:documents.view');
        Route::apiResource('vehicles', VehicleController::class)
            ->middlewareFor(['index', 'show'], 'permission:vehicles.view')
            ->middlewareFor('store', 'permission:vehicles.create')
            ->middlewareFor('update', 'permission:vehicles.update')
            ->middlewareFor('destroy', 'permission:vehicles.delete');
        Route::get('vehicles/{vehicle}/mileage', [MileageController::class, 'index'])->middleware('permission:mileage.view');
        Route::post('vehicles/{vehicle}/mileage', [MileageController::class, 'store'])->middleware('permission:mileage.create');
        Route::put('vehicles/{vehicle}/mileage/{mileageLog}', [MileageController::class, 'update'])->middleware('permission:mileage.update');
        Route::delete('vehicles/{vehicle}/mileage/{mileageLog}', [MileageController::class, 'destroy'])->middleware('permission:mileage.delete');
        Route::get('mileage/{mileageLog}/photo', [MileageController::class, 'photo'])->middleware('permission:mileage.view');
        Route::get('drivers/{driver}/files/{type}', [VehicleResourceController::class, 'driverFile'])->middleware('permission:drivers.view');
        Route::get('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'index'])->middleware('permission:maintenance.view');
        Route::post('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'store'])->middleware('permission:maintenance.create');
        Route::put('vehicles/{vehicle}/maintenance/{maintenanceRecord}', [MaintenanceController::class, 'update'])->middleware('permission:maintenance.update');
        Route::delete('vehicles/{vehicle}/maintenance/{maintenanceRecord}', [MaintenanceController::class, 'destroy'])->middleware('permission:maintenance.delete');
        Route::get('vehicles/{vehicle}/{resource}', [VehicleResourceController::class, 'index'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::get('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'showNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::post('vehicles/{vehicle}/{resource}', [VehicleResourceController::class, 'store'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::put('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'updateNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::delete('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'destroyNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        foreach (['drivers', 'assignments'] as $resource) {
            Route::get($resource, [VehicleResourceController::class, 'rootIndex'])->defaults('resource', $resource);
            Route::post($resource, [VehicleResourceController::class, 'rootStore'])->defaults('resource', $resource);
            Route::get("$resource/{id}", [VehicleResourceController::class, 'show'])->defaults('resource', $resource);
            Route::put("$resource/{id}", [VehicleResourceController::class, 'update'])->defaults('resource', $resource);
            Route::delete("$resource/{id}", [VehicleResourceController::class, 'destroy'])->defaults('resource', $resource);
        }
        Route::get('reports/expenses', [ReportController::class, 'expenses'])->middleware('permission:reports.view');
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->middleware('permission:notifications.update');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->middleware('permission:notifications.update');
    });
});
