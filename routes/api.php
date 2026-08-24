<?php

use App\Http\Controllers\Api\AccessManagement\MenuController;
use App\Http\Controllers\Api\AccessManagement\MenuIconController;
use App\Http\Controllers\Api\AccessManagement\CustomerController;
use App\Http\Controllers\Api\AccessManagement\CustomerMenuController;
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
use App\Http\Controllers\Api\CustomerAuth\TwoFactorChallengeController as CustomerTwoFactorChallengeController;
use App\Http\Controllers\Api\CustomerAuth\TwoFactorSettingsController as CustomerTwoFactorSettingsController;
use App\Http\Controllers\Api\CustomerAuth\RegisterController as CustomerRegisterController;
use App\Http\Controllers\Api\CustomerAuth\ResetPasswordController as CustomerResetPasswordController;
use App\Http\Controllers\Api\CustomerProfileController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\LandingPageSectionController;
use App\Http\Controllers\Api\LandingPageSectionItemController;
use App\Http\Controllers\Api\LayoutSettingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectSettingController;
use App\Http\Controllers\Api\PublicLandingPageController;
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

    // Access Management — Menus
    Route::prefix('access-management')->group(function () {
        Route::get('/menu-icons', [MenuIconController::class, 'index']);
        Route::apiResource('/menus', MenuController::class);
        Route::apiResource('/customer-menus', CustomerMenuController::class);
        Route::apiResource('/customers', CustomerController::class);

        // Roles
        Route::get('/roles/{role}/menu-permissions', [RoleController::class, 'menuPermissions']);
        Route::post('/roles/{role}/menu-permissions', [RoleController::class, 'saveMenuPermissions']);
        Route::apiResource('/roles', RoleController::class);

        // Users
        Route::post('/users/{user}/roles', [UserController::class, 'assignRoles']);
        Route::apiResource('/users', UserController::class);
    });
});
