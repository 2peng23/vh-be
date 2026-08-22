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
use App\Http\Controllers\Api\V1\PublicPlanOfferingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\SubscriptionPlanOfferingController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VehicleResourceController;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // -------------------------------------------------------------------------
    // Email Verification
    // -------------------------------------------------------------------------

    Route::get('email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return response()->json([
            'success' => true,
            'message' => 'Email verified.',
        ]);
    })->middleware(['auth:sanctum', 'signed'])->name('verification.verify');

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    Route::prefix('auth')->middleware('throttle:login')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        Route::post('forgot-password', function (ForgotPasswordRequest $request) {
            return response()->json([
                'success' => true,
                'message' => Password::sendResetLink($request->only('email')),
            ]);
        });
    });

    // -------------------------------------------------------------------------
    // Guest Support
    // -------------------------------------------------------------------------

    Route::get('public/plan-offerings', PublicPlanOfferingController::class)->middleware('throttle:60,1');

    Route::prefix('guest-support')->middleware('throttle:60,1')->group(function () {
        Route::post('conversations', [SupportController::class, 'createGuestConversation']);
        Route::get('messages', [SupportController::class, 'guestMessages']);
        Route::post('messages', [SupportController::class, 'guestSend']);
        Route::get('attachments/{supportMessage}', [SupportController::class, 'guestDownload']);
    });

    // -------------------------------------------------------------------------
    // Access Status
    // -------------------------------------------------------------------------

    Route::get('access-status', [AuthController::class, 'accessStatus'])->middleware('auth:sanctum');

    // -------------------------------------------------------------------------
    // Authenticated User
    // -------------------------------------------------------------------------

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->middleware('plan.active');
        Route::put('me', [AuthController::class, 'updateProfile']);
        Route::put('me/password', [AuthController::class, 'changePassword']);
    });

    // -------------------------------------------------------------------------
    // Super Admin
    // -------------------------------------------------------------------------

    Route::prefix('superadmin')->middleware(['auth:sanctum', 'superadmin'])->group(function () {

        // Dashboard
        Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
        Route::get('logistics', [SuperAdminController::class, 'logistics']);
        Route::post('logistics/import', [SuperAdminController::class, 'importLogistics']);

        // Businesses
        Route::get('businesses', [SuperAdminController::class, 'businesses']);
        Route::post('owners', [SuperAdminController::class, 'storeOwner']);
        Route::put('businesses/{business}', [SuperAdminController::class, 'updateBusiness']);

        // Transactions
        Route::get('transactions', [SuperAdminController::class, 'transactions']);
        Route::post('transactions', [SuperAdminController::class, 'storeTransaction']);
        Route::get('transactions/{planTransaction}/payment-proof', [SuperAdminController::class, 'paymentProof']);
        Route::put('transactions/{planTransaction}/payment-review', [SuperAdminController::class, 'reviewPayment']);

        // Subscription Plan Offerings
        Route::apiResource('plan-offerings', SubscriptionPlanOfferingController::class)->only(['index', 'store', 'update']);

        // Payment Methods
        Route::apiResource('payment-methods', PaymentMethodController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('payment-methods/{paymentMethod}/qr', [PaymentMethodController::class, 'qr']);

        // Users
        Route::get('users', [SuperAdminController::class, 'users']);
        Route::put('users/{user}', [SuperAdminController::class, 'updateUser']);
        Route::post('users/{user}/impersonate', [SuperAdminController::class, 'impersonate']);

        // Permissions
        Route::get('permissions', [SuperAdminController::class, 'permissions']);
        Route::get('permissions/users', [SuperAdminController::class, 'permissionUsers']);
        Route::get('permissions/users/{user}', [SuperAdminController::class, 'permissionUser']);
        Route::put('permissions/apply-to-role', [SuperAdminController::class, 'applyPermissionsToRole']);
        Route::put('permissions/users/{user}', [SuperAdminController::class, 'updateUserPermissions']);

        // Support
        Route::get('support/conversations', [SupportController::class, 'conversations']);
        Route::get('support/businesses/{business}', [SupportController::class, 'adminMessages']);
        Route::post('support/businesses/{business}', [SupportController::class, 'adminSend']);
        Route::get('support/guests/{guestSupportConversation}', [SupportController::class, 'adminGuestMessages']);
        Route::post('support/guests/{guestSupportConversation}', [SupportController::class, 'adminGuestSend']);

        // Support Templates
        Route::get('support/templates', [SupportController::class, 'templates']);
        Route::post('support/templates', [SupportController::class, 'storeTemplate']);
        Route::put('support/templates/{supportTemplate}', [SupportController::class, 'updateTemplate']);
    });

    // -------------------------------------------------------------------------
    // Tenant Routes
    // -------------------------------------------------------------------------

    Route::middleware(['auth:sanctum', 'plan.active'])->group(function () {

        // ---------------------------------------------------------------------
        // Support
        // ---------------------------------------------------------------------

        Route::get('support/messages', [SupportController::class, 'messages']);
        Route::post('support/messages', [SupportController::class, 'send']);
        Route::get('support/unread-count', [SupportController::class, 'unreadCount']);
        Route::get('support/attachments/{supportMessage}', [SupportController::class, 'download']);

        // ---------------------------------------------------------------------
        // Subscription Plan Transactions
        // ---------------------------------------------------------------------

        Route::get('plan-transactions', [PlanTransactionController::class, 'index']);
        Route::post('subscription/preview', [PlanTransactionController::class, 'preview']);
        Route::post('plan-transactions', [PlanTransactionController::class, 'store']);
        Route::get('plan-transactions/{planTransaction}', [PlanTransactionController::class, 'show']);
        Route::post('plan-transactions/{planTransaction}/submit-payment', [PlanTransactionController::class, 'submitPayment']);
        Route::get('plan-transactions/{planTransaction}/payment-qr', [PlanTransactionController::class, 'paymentQr']);

        // ---------------------------------------------------------------------
        // Subscription Plan Offerings
        // ---------------------------------------------------------------------

        Route::get('plan-offerings', [SubscriptionPlanOfferingController::class, 'index']);

        // ---------------------------------------------------------------------
        // Payment Methods
        // ---------------------------------------------------------------------

        Route::get('payment-methods', [PaymentMethodController::class, 'index']);

        // ---------------------------------------------------------------------
        // Staff
        // ---------------------------------------------------------------------

        Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view');
        Route::post('staff', [StaffController::class, 'store'])->middleware('permission:staff.create');
        Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.update');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('permission:staff.delete');

        // ---------------------------------------------------------------------
        // Dashboard
        // ---------------------------------------------------------------------

        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');

        // ---------------------------------------------------------------------
        // Audit Logs
        // ---------------------------------------------------------------------

        Route::get('audit-logs/options', [AuditLogController::class, 'options'])->middleware('permission:audit.view');
        Route::get('audit-logs/{auditLog}/document-file', [AuditLogController::class, 'documentFile'])->middleware('permission:audit.view');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        // ---------------------------------------------------------------------
        // Operations
        // ---------------------------------------------------------------------

        Route::get('maintenance', [OperationsController::class, 'maintenance'])->middleware('permission:maintenance.view');
        Route::get('issues', [OperationsController::class, 'issues'])->middleware('permission:issues.view');
        Route::get('documents', [OperationsController::class, 'documents'])->middleware('permission:documents.view');

        // ---------------------------------------------------------------------
        // Assignees
        // ---------------------------------------------------------------------

        Route::get('assignees', [VehicleResourceController::class, 'assignees']);

        // ---------------------------------------------------------------------
        // Vehicles
        // ---------------------------------------------------------------------

        Route::apiResource('vehicles', VehicleController::class)
            ->middlewareFor(['index', 'show'], 'permission:vehicles.view')
            ->middlewareFor('store', 'permission:vehicles.create')
            ->middlewareFor('update', 'permission:vehicles.update')
            ->middlewareFor('destroy', 'permission:vehicles.delete');

        // ---------------------------------------------------------------------
        // Mileage
        // ---------------------------------------------------------------------

        Route::get('vehicles/{vehicle}/mileage', [MileageController::class, 'index'])->middleware('permission:mileage.view');
        Route::post('vehicles/{vehicle}/mileage', [MileageController::class, 'store'])->middleware('permission:mileage.create');
        Route::put('vehicles/{vehicle}/mileage/{mileageLog}', [MileageController::class, 'update'])->middleware('permission:mileage.update');
        Route::delete('vehicles/{vehicle}/mileage/{mileageLog}', [MileageController::class, 'destroy'])->middleware('permission:mileage.delete');
        Route::get('mileage/{mileageLog}/photo', [MileageController::class, 'photo'])->middleware('permission:mileage.view');

        // ---------------------------------------------------------------------
        // Maintenance
        // ---------------------------------------------------------------------

        Route::get('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'index'])->middleware('permission:maintenance.view');
        Route::post('vehicles/{vehicle}/maintenance', [MaintenanceController::class, 'store'])->middleware('permission:maintenance.create');
        Route::put('vehicles/{vehicle}/maintenance/{maintenanceRecord}', [MaintenanceController::class, 'update'])->middleware('permission:maintenance.update');
        Route::delete('vehicles/{vehicle}/maintenance/{maintenanceRecord}', [MaintenanceController::class, 'destroy'])->middleware('permission:maintenance.delete');

        // ---------------------------------------------------------------------
        // Documents
        // ---------------------------------------------------------------------

        Route::get('documents/{document}/download', [VehicleResourceController::class, 'download'])->middleware('permission:documents.view');

        // ---------------------------------------------------------------------
        // Driver Files
        // ---------------------------------------------------------------------

        Route::get('drivers/{driver}/files/{type}', [VehicleResourceController::class, 'driverFile'])->middleware('permission:drivers.view');

        // ---------------------------------------------------------------------
        // Vehicle Nested Resources
        // ---------------------------------------------------------------------

        Route::get('vehicles/{vehicle}/{resource}', [VehicleResourceController::class, 'index'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::get('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'showNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::post('vehicles/{vehicle}/{resource}', [VehicleResourceController::class, 'store'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::put('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'updateNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);
        Route::delete('vehicles/{vehicle}/{resource}/{id}', [VehicleResourceController::class, 'destroyNested'])->whereIn('resource', ['documents', 'expenses', 'issues', 'fuel', 'schedules']);

        // ---------------------------------------------------------------------
        // Drivers
        // ---------------------------------------------------------------------

        Route::get('drivers', [VehicleResourceController::class, 'rootIndex'])->defaults('resource', 'drivers');
        Route::post('drivers', [VehicleResourceController::class, 'rootStore'])->defaults('resource', 'drivers');
        Route::get('drivers/{id}', [VehicleResourceController::class, 'show'])->defaults('resource', 'drivers');
        Route::put('drivers/{id}', [VehicleResourceController::class, 'update'])->defaults('resource', 'drivers');
        Route::delete('drivers/{id}', [VehicleResourceController::class, 'destroy'])->defaults('resource', 'drivers');

        // ---------------------------------------------------------------------
        // Assignments
        // ---------------------------------------------------------------------

        Route::get('assignments', [VehicleResourceController::class, 'rootIndex'])->defaults('resource', 'assignments');
        Route::post('assignments', [VehicleResourceController::class, 'rootStore'])->defaults('resource', 'assignments');
        Route::get('assignments/{id}', [VehicleResourceController::class, 'show'])->defaults('resource', 'assignments');
        Route::put('assignments/{id}', [VehicleResourceController::class, 'update'])->defaults('resource', 'assignments');
        Route::delete('assignments/{id}', [VehicleResourceController::class, 'destroy'])->defaults('resource', 'assignments');

        // ---------------------------------------------------------------------
        // Reports
        // ---------------------------------------------------------------------

        Route::get('reports/expenses', [ReportController::class, 'expenses'])->middleware('permission:reports.view');

        // ---------------------------------------------------------------------
        // Notifications
        // ---------------------------------------------------------------------

        Route::get('notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('permission:notifications.view');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->middleware('permission:notifications.update');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->middleware('permission:notifications.update');
    });
});
