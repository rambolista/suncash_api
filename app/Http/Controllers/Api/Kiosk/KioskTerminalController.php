<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\KioskTerminal;
use App\Services\Kiosk\KioskTerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KioskTerminalController extends Controller
{
    private const MODULE_PATH = '/kiosk/management';

    public function __construct(private readonly KioskTerminalService $terminals) {}

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

    public function index(Request $request, int $branchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'data' => $this->terminals->list($branchId),
            'islands' => $this->terminals->listIslands(),
            'managers' => $this->terminals->listManagers(),
            'commission_profiles' => $this->terminals->listCommissionProfiles(),
            'commission_types' => KioskTerminal::COMMISSION_TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $terminal = $this->terminals->create($request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Kiosk Terminal has been registered.', 'terminal' => $terminal], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $terminal = $this->terminals->update($id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Kiosk Terminal has been updated.', 'terminal' => $terminal]);
    }

    public function updateCommission(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $terminal = $this->terminals->updateCommission($id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Commission has been updated successfully.', 'terminal' => $terminal]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->terminals->delete($id, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Kiosk Terminal has been deleted.']);
    }
}
