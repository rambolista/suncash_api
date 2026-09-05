<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskProductProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskProductProfileController extends Controller
{
    private const MODULE_PATH = '/kiosk/product-profiles';

    public function __construct(private readonly KioskProductProfileService $profiles) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function actorName(Request $request): string
    {
        return $request->user()?->name ?? $request->user()?->email ?? 'system';
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'terminals' => $this->profiles->listTerminals(),
            'modules' => $this->profiles->listModules(),
        ]);
    }

    public function terminalModules(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $modules = $this->profiles->terminalModules($terminalId);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['modules' => $modules]);
    }

    public function addServices(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        $data = $request->validate(['module_ids' => ['required', 'array'], 'module_ids.*' => ['integer']]);

        try {
            $this->profiles->addServices($terminalId, $data['module_ids'], $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'updated', "Added service(s) to kiosk terminal #{$terminalId}", null, $request);

        return response()->json(['message' => 'Service(s) have been added.']);
    }

    public function replaceServices(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['module_ids' => ['present', 'array'], 'module_ids.*' => ['integer']]);

        try {
            $this->profiles->replaceServices($terminalId, $data['module_ids'], $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'updated', "Updated product profile for kiosk terminal #{$terminalId}", null, $request);

        return response()->json(['message' => 'Product profile has been updated.']);
    }

    public function disable(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $this->profiles->disableTerminal($terminalId, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'disabled', "Disabled kiosk terminal #{$terminalId}", null, $request);

        return response()->json(['message' => 'Kiosk terminal has been disabled.']);
    }

    public function enable(Request $request, int $terminalId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $this->profiles->enableTerminal($terminalId, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'enabled', "Enabled kiosk terminal #{$terminalId}", null, $request);

        return response()->json(['message' => 'Kiosk terminal has been enabled.']);
    }

    public function updateModuleStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['status' => ['required', 'string', 'in:ACTIVE,INACTIVE']]);

        try {
            $this->profiles->updateModuleStatus($id, $data['status']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'updated', "Set kiosk module #{$id} to {$data['status']}", null, $request);

        return response()->json(['message' => 'Module has been updated.']);
    }

    public function featureSettings(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['settings' => $this->profiles->listFeatureSettings()]);
    }

    public function updateFeatureSetting(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $this->profiles->updateFeatureSetting($id, (bool) $data['is_active']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Product Profiles', 'updated', 'Updated kiosk feature setting #'.$id, null, $request);

        return response()->json(['message' => 'Feature setting has been updated.']);
    }
}
