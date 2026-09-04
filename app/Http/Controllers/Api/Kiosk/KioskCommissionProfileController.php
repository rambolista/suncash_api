<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCommissionProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskCommissionProfileController extends Controller
{
    private const MODULE_PATH = '/kiosk/commission-profiles';

    public function __construct(private readonly KioskCommissionProfileService $profiles) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], 422);
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
            'profiles' => $this->profiles->listProfileNames(),
            'products' => $this->profiles->listProducts(),
        ]);
    }

    public function show(Request $request, string $profileName): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->profiles->showProfile($profileName)]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        $data = $request->validate(['profile_name' => ['required', 'string', 'max:100']]);

        try {
            $this->profiles->createProfile(trim($data['profile_name']), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Profiles', 'created', "Created commission profile \"{$data['profile_name']}\"", null, $request);

        return response()->json(['message' => 'Profile created.']);
    }

    public function copy(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        $data = $request->validate([
            'source_profile_name' => ['required', 'string', 'max:100'],
            'new_profile_name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->profiles->copyProfile(trim($data['source_profile_name']), trim($data['new_profile_name']), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Profiles', 'created', "Copied commission profile \"{$data['source_profile_name']}\" to \"{$data['new_profile_name']}\"", null, $request);

        return response()->json(['message' => 'Profile copied.']);
    }

    public function updateRow(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'provider_percentage' => ['required', 'numeric', 'min:0'],
            'cap_amount' => ['required', 'numeric', 'min:0'],
            'minimum_amount' => ['required', 'numeric', 'min:0'],
            'frequency_in_limit_days' => ['required', 'integer', 'min:0'],
            'agent_percentage' => ['required', 'numeric', 'min:0'],
            'suncash_percentage' => ['required', 'numeric', 'min:0'],
            'owner_percentage' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->profiles->updateRow($id, $data, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Profiles', 'updated', "Updated commission profile row #{$id}", null, $request);

        return response()->json(['message' => 'Commission updated.']);
    }

    public function destroy(Request $request, string $profileName): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->profiles->deleteProfile($profileName);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Commission Profiles', 'deleted', "Deleted commission profile \"{$profileName}\"", null, $request);

        return response()->json(['message' => 'Profile deleted.']);
    }
}
