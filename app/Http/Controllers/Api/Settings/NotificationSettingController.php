<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\NotificationSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NotificationSettingController extends Controller
{
    private const MODULE_PATH = '/settings/notifications';

    public function __construct(private readonly NotificationSettingService $notifications) {}

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

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->notifications->listByType((string) $request->query('type', 'email')));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->notifications->find($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $setting = $this->notifications->update($id, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Notification setting updated successfully.', 'setting' => $setting]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->notifications->toggle($id, $request->boolean('is_enabled'));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => $result['is_enabled'] ? 'Setting enabled.' : 'Setting disabled.'] + $result);
    }
}
