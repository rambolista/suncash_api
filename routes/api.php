<?php

use App\Http\Controllers\Api\AccessManagement\CustomerController;
use App\Http\Controllers\Api\AccessManagement\CustomerMenuController;
use App\Http\Controllers\Api\AccessManagement\MenuController;
use App\Http\Controllers\Api\AccessManagement\MenuIconController;
use App\Http\Controllers\Api\AccessManagement\MerchantBranchController;
use App\Http\Controllers\Api\AccessManagement\MerchantController;
use App\Http\Controllers\Api\AccessManagement\MerchantFloatAccountController;
use App\Http\Controllers\Api\AccessManagement\MerchantMoneyController;
use App\Http\Controllers\Api\AccessManagement\MerchantOperationsController;
use App\Http\Controllers\Api\AccessManagement\MerchantPosUserController;
use App\Http\Controllers\Api\AccessManagement\MerchantTerminalController;
use App\Http\Controllers\Api\AccessManagement\RoleController;
use App\Http\Controllers\Api\AccessManagement\UserController;
use App\Http\Controllers\Api\Auth\ChangePasswordController;
use App\Http\Controllers\Api\Auth\DeleteAccountController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PinController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\Auth\TwoFactorSettingsController;
use App\Http\Controllers\Api\Auth\UnlockController;
use App\Http\Controllers\Api\Auth\UserController as AuthUserController;
use App\Http\Controllers\Api\CustomerAuth\ForgotPasswordController as CustomerForgotPasswordController;
use App\Http\Controllers\Api\CustomerAuth\LoginController as CustomerLoginController;
use App\Http\Controllers\Api\CustomerAuth\RegisterController as CustomerRegisterController;
use App\Http\Controllers\Api\CustomerAuth\ResetPasswordController as CustomerResetPasswordController;
use App\Http\Controllers\Api\CustomerAuth\TwoFactorChallengeController as CustomerTwoFactorChallengeController;
use App\Http\Controllers\Api\CustomerAuth\TwoFactorSettingsController as CustomerTwoFactorSettingsController;
use App\Http\Controllers\Api\CustomerProfileController;
use App\Http\Controllers\Api\Dashboard\MerchantDashboardController;
use App\Http\Controllers\Api\FloatManagement\CurrentStoreFloatController;
use App\Http\Controllers\Api\FloatManagement\MainReserveAccountController;
use App\Http\Controllers\Api\FloatManagement\SetMainReserveAccountController;
use App\Http\Controllers\Api\FloatManagement\StoreFloatReplenishmentController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\LandingPageSectionController;
use App\Http\Controllers\Api\LandingPageSectionItemController;
use App\Http\Controllers\Api\LayoutSettingController;
use App\Http\Controllers\Api\MerchantType\BusinessManagementController;
use App\Http\Controllers\Api\MerchantType\CharityManagementController;
use App\Http\Controllers\Api\MerchantType\MerchantOwnerController;
use App\Http\Controllers\Api\MerchantType\MerchantTypeLookupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectSettingController;
use App\Http\Controllers\Api\Promotions\CashPromoSettingController;
use App\Http\Controllers\Api\Promotions\GeoPromoController;
use App\Http\Controllers\Api\Promotions\PromoItemController;
use App\Http\Controllers\Api\Promotions\PromoLookupController;
use App\Http\Controllers\Api\Promotions\PromoTicketReportController;
use App\Http\Controllers\Api\PublicLandingPageController;
use App\Http\Controllers\Api\Settings\CustomerAppSettingController;
use App\Http\Controllers\Api\Settings\NotificationSettingController;
use App\Http\Controllers\Api\Settings\WuSettingController;
use App\Http\Controllers\Api\ThemePreferenceController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

// ── Public auth routes (no token required) ─────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login', LoginController::class);
    Route::post('/register', RegisterController::class);
    Route::post('/forgot-password', ForgotPasswordController::class);
    Route::post('/reset-password', ResetPasswordController::class);
    Route::post('/2fa/challenge/verify', TwoFactorChallengeController::class)->middleware('throttle:10,1');
});

Route::prefix('customer')->group(function () {
    Route::post('/login', CustomerLoginController::class);
    Route::post('/register', CustomerRegisterController::class);
    Route::post('/forgot-password', CustomerForgotPasswordController::class);
    Route::post('/reset-password', CustomerResetPasswordController::class);
    Route::post('/two-factor/challenge/verify', CustomerTwoFactorChallengeController::class);
});

Route::get('/project-settings', [ProjectSettingController::class, 'show']);
Route::get('/layout-settings', [LayoutSettingController::class, 'show']);
Route::get('/landing-page', [PublicLandingPageController::class, 'show']);

// ── Protected routes (valid Sanctum token required) ────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', LogoutController::class);
        Route::get('/user', AuthUserController::class);
        Route::put('/change-password', ChangePasswordController::class);
        Route::post('/unlock', UnlockController::class)->middleware('throttle:5,1');
        Route::get('/2fa', [TwoFactorSettingsController::class, 'status']);
        Route::post('/2fa/setup', [TwoFactorSettingsController::class, 'setup'])->middleware('throttle:5,1');
        Route::post('/2fa/confirm', [TwoFactorSettingsController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/2fa/disable', [TwoFactorSettingsController::class, 'disable']);
        Route::put('/pin', [PinController::class, 'update']);
        Route::post('/pin/verify', [PinController::class, 'verify'])->middleware('throttle:5,1');
        Route::delete('/account', DeleteAccountController::class);
    });

    // User profile
    Route::prefix('user')->group(function () {
        Route::get('/profile', [UserProfileController::class, 'show']);
        Route::get('/profile/history', [UserProfileController::class, 'history']);
        Route::put('/profile', [UserProfileController::class, 'update']);
        Route::post('/avatar', [UserProfileController::class, 'updateAvatar']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
    });

    Route::put('/layout-settings', [LayoutSettingController::class, 'update']);
    Route::put('/layout-settings/theme', [LayoutSettingController::class, 'updateTheme']);
    Route::put('/user/theme-preference', [ThemePreferenceController::class, 'update']);
    Route::put('/project-settings', [ProjectSettingController::class, 'update']);
    Route::get('/customer-menus', [CustomerMenuController::class, 'index']);

    Route::prefix('customer')->group(function () {
        Route::get('/2fa', [CustomerTwoFactorSettingsController::class, 'status']);
        Route::post('/2fa/setup', [CustomerTwoFactorSettingsController::class, 'setup']);
        Route::post('/2fa/confirm', [CustomerTwoFactorSettingsController::class, 'confirm']);
        Route::post('/2fa/disable', [CustomerTwoFactorSettingsController::class, 'disable']);
    });

    Route::prefix('customer')->group(function () {
        Route::get('/profile', [CustomerProfileController::class, 'show']);
        Route::put('/profile', [CustomerProfileController::class, 'update']);
    });

    Route::prefix('landing-pages')->group(function () {
        Route::get('/', [LandingPageController::class, 'index']);
        Route::post('/', [LandingPageController::class, 'store']);
        Route::post('/{landingPage}/duplicate', [LandingPageController::class, 'duplicate']);
        Route::get('/{landingPage}', [LandingPageController::class, 'show']);
        Route::match(['put', 'patch'], '/{landingPage}', [LandingPageController::class, 'update']);
        Route::delete('/{landingPage}', [LandingPageController::class, 'destroy']);

        Route::get('/{landingPage}/sections', [LandingPageSectionController::class, 'index']);
        Route::post('/{landingPage}/sections', [LandingPageSectionController::class, 'store']);
        Route::patch('/{landingPage}/sections/reorder', [LandingPageSectionController::class, 'reorder']);
        Route::get('/{landingPage}/sections/{section}', [LandingPageSectionController::class, 'show']);
        Route::match(['put', 'patch'], '/{landingPage}/sections/{section}', [LandingPageSectionController::class, 'update']);
        Route::delete('/{landingPage}/sections/{section}', [LandingPageSectionController::class, 'destroy']);

        Route::get('/{landingPage}/sections/{section}/items', [LandingPageSectionItemController::class, 'index']);
        Route::post('/{landingPage}/sections/{section}/items', [LandingPageSectionItemController::class, 'store']);
        Route::patch('/{landingPage}/sections/{section}/items/reorder', [LandingPageSectionItemController::class, 'reorder']);
        Route::get('/{landingPage}/sections/{section}/items/{item}', [LandingPageSectionItemController::class, 'show']);
        Route::match(['put', 'patch'], '/{landingPage}/sections/{section}/items/{item}', [LandingPageSectionItemController::class, 'update']);
        Route::delete('/{landingPage}/sections/{section}/items/{item}', [LandingPageSectionItemController::class, 'destroy']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/merchants', [MerchantDashboardController::class, 'index']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationSettingController::class, 'index']);
            Route::get('/{id}', [NotificationSettingController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [NotificationSettingController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/toggle', [NotificationSettingController::class, 'toggle'])->whereNumber('id');
        });

        Route::prefix('customer-app')->group(function () {
            Route::get('/', [CustomerAppSettingController::class, 'index']);
            Route::post('/{id}/toggle', [CustomerAppSettingController::class, 'toggle'])->whereNumber('id');
        });

        Route::prefix('wu')->group(function () {
            Route::get('/', [WuSettingController::class, 'index']);
            Route::post('/{id}/toggle', [WuSettingController::class, 'toggle'])->whereNumber('id');
        });
    });

    // Promotions
    Route::prefix('promotions')->group(function () {
        Route::get('/islands', [PromoLookupController::class, 'islands']);
        Route::get('/countries', [PromoLookupController::class, 'countries']);
        Route::get('/merchants', [PromoLookupController::class, 'merchants']);
        Route::get('/branches', [PromoLookupController::class, 'branches']);

        Route::get('/ticket-reports', [PromoTicketReportController::class, 'index']);
        Route::get('/ticket-reports/export', [PromoTicketReportController::class, 'export']);

        Route::prefix('cash-promos')->group(function () {
            Route::get('/', [CashPromoSettingController::class, 'index']);
            Route::post('/', [CashPromoSettingController::class, 'store']);
            Route::put('/{id}', [CashPromoSettingController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [CashPromoSettingController::class, 'destroy'])->whereNumber('id');
        });

        Route::prefix('promo-items')->group(function () {
            Route::get('/', [PromoItemController::class, 'index']);
            Route::post('/', [PromoItemController::class, 'store']);
            Route::post('/{id}', [PromoItemController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [PromoItemController::class, 'destroy'])->whereNumber('id');
        });

        Route::prefix('geo-promo')->group(function () {
            Route::get('/', [GeoPromoController::class, 'index']);
            Route::get('/{id}', [GeoPromoController::class, 'show'])->whereNumber('id');
            Route::post('/', [GeoPromoController::class, 'store']);
            Route::put('/{id}', [GeoPromoController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [GeoPromoController::class, 'destroy'])->whereNumber('id');
        });
    });

    // Float Management
    Route::prefix('float-management')->group(function () {
        Route::prefix('main-reserve-account')->group(function () {
            Route::get('/', [MainReserveAccountController::class, 'index']);
            Route::post('/{id}/approve', [MainReserveAccountController::class, 'approve'])->whereNumber('id');
            Route::post('/{id}/reject', [MainReserveAccountController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/confirm', [MainReserveAccountController::class, 'confirm'])->whereNumber('id');
            Route::post('/topup', [MainReserveAccountController::class, 'topup']);
            Route::post('/request-replenishment', [MainReserveAccountController::class, 'requestReplenishment']);
        });

        Route::prefix('set-main-reserve-account')->group(function () {
            Route::get('/', [SetMainReserveAccountController::class, 'show']);
            Route::post('/', [SetMainReserveAccountController::class, 'setup']);
            Route::put('/', [SetMainReserveAccountController::class, 'update']);
        });

        Route::prefix('store-float-replenishments')->group(function () {
            Route::get('/', [StoreFloatReplenishmentController::class, 'index']);
            Route::post('/{id}/approve', [StoreFloatReplenishmentController::class, 'approve'])->whereNumber('id');
            Route::post('/{id}/reject', [StoreFloatReplenishmentController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/confirm', [StoreFloatReplenishmentController::class, 'confirm'])->whereNumber('id');
        });

        Route::prefix('current-store-float-amounts')->group(function () {
            Route::get('/', [CurrentStoreFloatController::class, 'index']);
            Route::post('/create-account', [CurrentStoreFloatController::class, 'createAccount']);
            Route::post('/{id}/topup', [CurrentStoreFloatController::class, 'topup'])->whereNumber('id');
            Route::post('/{id}/request-replenishment', [CurrentStoreFloatController::class, 'requestReplenishment'])->whereNumber('id');
        });
    });

    // Merchants — Business Management / Charity Management
    Route::prefix('merchant-type-lookups')->group(function () {
        Route::get('/sole-proprietorships', [MerchantTypeLookupController::class, 'soleProprietorships']);
        Route::get('/business-categories', [MerchantTypeLookupController::class, 'businessCategories']);
        Route::get('/id-types', [MerchantTypeLookupController::class, 'idTypes']);
        Route::get('/position-levels', [MerchantTypeLookupController::class, 'positionLevels']);
        Route::get('/islands', [MerchantTypeLookupController::class, 'islands']);
        Route::get('/countries', [MerchantTypeLookupController::class, 'countries']);
    });

    Route::prefix('business-management')->group(function () {
        Route::get('/', [BusinessManagementController::class, 'index']);
        Route::get('/{id}', [BusinessManagementController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [BusinessManagementController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/approve', [BusinessManagementController::class, 'approve'])->whereNumber('id');
        Route::post('/{id}/reject', [BusinessManagementController::class, 'reject'])->whereNumber('id');
        Route::post('/{id}/owners', [MerchantOwnerController::class, 'store'])->whereNumber('id');
        Route::put('/{id}/owners/{ownerId}', [MerchantOwnerController::class, 'update'])->whereNumber('id')->whereNumber('ownerId');
    });

    Route::prefix('charity-management')->group(function () {
        Route::get('/', [CharityManagementController::class, 'index']);
        Route::get('/{id}', [CharityManagementController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [CharityManagementController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/approve', [CharityManagementController::class, 'approve'])->whereNumber('id');
        Route::post('/{id}/reject', [CharityManagementController::class, 'reject'])->whereNumber('id');
    });

    // Access Management — Menus
    Route::prefix('access-management')->group(function () {
        Route::get('/menu-icons', [MenuIconController::class, 'index']);
        Route::apiResource('/menus', MenuController::class);
        Route::apiResource('/customer-menus', CustomerMenuController::class);
        Route::apiResource('/customers', CustomerController::class);

        Route::prefix('merchants')->group(function () {
            Route::get('/', [MerchantController::class, 'index']);
            Route::get('/check-id', [MerchantController::class, 'checkId']);
            Route::get('/check-username', [MerchantController::class, 'checkUsername']);
            Route::post('/logo-upload', [MerchantController::class, 'uploadLogo']);
            Route::get('/branches/islands', [MerchantBranchController::class, 'islands']);
            Route::post('/', [MerchantController::class, 'store']);
            Route::get('/{id}', [MerchantController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [MerchantController::class, 'update'])->whereNumber('id');

            Route::prefix('/{id}')->whereNumber('id')->group(function () {
                Route::get('/principal-info', [MerchantOperationsController::class, 'showPrincipalInfo']);
                Route::put('/principal-info', [MerchantOperationsController::class, 'savePrincipalInfo']);
                Route::post('/reset-password', [MerchantOperationsController::class, 'resetPassword']);
                Route::get('/users', [MerchantOperationsController::class, 'listUsers']);
                Route::post('/users', [MerchantOperationsController::class, 'addUser']);
                Route::post('/toggle-status', [MerchantOperationsController::class, 'toggleStatus']);
                Route::get('/ezpay-access', [MerchantOperationsController::class, 'showEzpayAccess']);
                Route::put('/ezpay-access', [MerchantOperationsController::class, 'updateEzpayAccess']);
                Route::get('/services', [MerchantOperationsController::class, 'listServices']);
                Route::put('/services', [MerchantOperationsController::class, 'updateServices']);

                // Prefund / Auto Replenish / Agent Commission Settings
                Route::post('/prefund', [MerchantMoneyController::class, 'adjustPrefund']);
                Route::get('/auto-replenish', [MerchantMoneyController::class, 'showAutoReplenish']);
                Route::put('/auto-replenish', [MerchantMoneyController::class, 'updateAutoReplenish']);
                Route::get('/agent-commission', [MerchantMoneyController::class, 'showAgentCommission']);
                Route::put('/agent-commission', [MerchantMoneyController::class, 'updateAgentCommission']);
                Route::post('/agent-commission/emails', [MerchantMoneyController::class, 'addAgentCommissionEmail']);
                Route::put('/agent-commission/emails/{emailId}', [MerchantMoneyController::class, 'updateAgentCommissionEmail'])->whereNumber('emailId');
                Route::delete('/agent-commission/emails/{emailId}', [MerchantMoneyController::class, 'deleteAgentCommissionEmail'])->whereNumber('emailId');

                // Branches
                Route::get('/branches', [MerchantBranchController::class, 'index']);
                Route::post('/branches', [MerchantBranchController::class, 'store']);
                Route::put('/branches/{branchId}', [MerchantBranchController::class, 'update'])->whereNumber('branchId');
                Route::post('/branches/{branchId}/status', [MerchantBranchController::class, 'changeStatus'])->whereNumber('branchId');

                // Terminals
                Route::get('/terminals', [MerchantTerminalController::class, 'index']);
                Route::post('/terminals', [MerchantTerminalController::class, 'store']);
                Route::put('/terminals/{terminalId}', [MerchantTerminalController::class, 'update'])->whereNumber('terminalId');
                Route::post('/terminals/{terminalId}/status', [MerchantTerminalController::class, 'changeStatus'])->whereNumber('terminalId');

                // POS Users
                Route::get('/pos-users', [MerchantPosUserController::class, 'index']);
                Route::post('/pos-users', [MerchantPosUserController::class, 'store']);
                Route::put('/pos-users/{userId}', [MerchantPosUserController::class, 'update'])->whereNumber('userId');
                Route::delete('/pos-users/{userId}', [MerchantPosUserController::class, 'destroy'])->whereNumber('userId');

                // Store Float Account
                Route::get('/float-account', [MerchantFloatAccountController::class, 'show']);
                Route::post('/float-account/toggle', [MerchantFloatAccountController::class, 'toggle']);
                Route::post('/float-account/request', [MerchantFloatAccountController::class, 'request']);
                Route::put('/float-account', [MerchantFloatAccountController::class, 'update']);
            });
        });

        // Roles
        Route::get('/roles/{role}/menu-permissions', [RoleController::class, 'menuPermissions']);
        Route::post('/roles/{role}/menu-permissions', [RoleController::class, 'saveMenuPermissions']);
        Route::apiResource('/roles', RoleController::class);

        // Users
        Route::post('/users/{user}/roles', [UserController::class, 'assignRoles']);
        Route::apiResource('/users', UserController::class);
    });
});
