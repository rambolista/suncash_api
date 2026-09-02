<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskUserController extends Controller
{
    private const MODULE_PATH = '/kiosk/users';

    public function __construct(private readonly KioskUserService $users) {}

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

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json([
            'data' => $this->users->list($branchId),
            'branches' => $this->users->listBranches(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $user = $this->users->create($request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Users', 'created', "Added {$user['user_type']} user {$user['username']}", null, $request);

        return response()->json(['message' => 'Kiosk user has been added.', 'user' => $user], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $currentType = strtolower($type) === 'admin' ? 'admin' : 'kiosk';
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $user = $this->users->update($currentType, $id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Users', 'updated', "Updated {$currentType} user #{$id} (now {$user['user_type']})", null, $request);

        return response()->json(['message' => 'Kiosk user has been updated.', 'user' => $user]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->users->delete($id, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Users', 'deleted', "Deleted kiosk user #{$id}", null, $request);

        return response()->json(['message' => 'Kiosk user has been deleted.']);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->users->resetPassword($id, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Users', 'reset_password', "Reset password for kiosk user {$result['username']}", null, $request);

        return response()->json(['message' => 'Password successfully reset. Check your email.'] + $result);
    }
}
