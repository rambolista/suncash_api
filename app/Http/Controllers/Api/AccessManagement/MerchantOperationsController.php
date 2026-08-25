<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Services\Merchant\MerchantOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantOperationsController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantOperationsService $operations)
    {
    }

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function notFound(ValidationException $exception): JsonResponse
    {
        return response()->json(['message' => 'Merchant not found.', 'errors' => $exception->errors()], 404);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
    }

    // ── Principal Info ──────────────────────────────────────────────────────

    public function showPrincipalInfo(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->operations->getPrincipalInfo($id));
    }

    public function savePrincipalInfo(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $principal = $this->operations->savePrincipalInfo($id, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Principal info saved successfully.', 'principal' => $principal]);
    }

    // ── Password reset ──────────────────────────────────────────────────────

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->operations->resetPassword($id, (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json([
            'message' => "Password reset — new credentials were e-mailed to {$result['email']}.",
            'username' => $result['username'],
        ]);
    }

    // ── Portal user management ──────────────────────────────────────────────

    public function listUsers(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->operations->listUsers($id));
    }

    public function addUser(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->operations->addUser($id, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'User added successfully.'] + $result, 201);
    }

    // ── Activate / deactivate ───────────────────────────────────────────────

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->operations->toggleStatus($id, (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->notFound($exception);
        }

        return response()->json([
            'message' => $result['active'] ? 'Merchant activated.' : 'Merchant deactivated.',
        ] + $result);
    }

    // ── Ezpay access ─────────────────────────────────────────────────────────

    public function showEzpayAccess(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'options' => MerchantOperationsService::EZPAY_TRANSACTION_TYPES,
            'granted' => $this->operations->getEzpayAccess($id),
        ]);
    }

    public function updateEzpayAccess(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $granted = $this->operations->updateEzpayAccess($id, (array) $request->input('access', []), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->notFound($exception);
        }

        return response()->json(['message' => 'Ezpay access updated successfully.', 'granted' => $granted]);
    }

    // ── Services permission ─────────────────────────────────────────────────

    public function listServices(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->operations->listServices($id));
    }

    public function updateServices(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $services = $this->operations->updateServices($id, (array) $request->input('service_ids', []), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->notFound($exception);
        }

        return response()->json(['message' => 'Services permission updated successfully.', 'services' => $services]);
    }
}
