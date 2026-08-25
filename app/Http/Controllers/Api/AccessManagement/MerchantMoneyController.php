<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Services\Merchant\MerchantMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantMoneyController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantMoneyService $money)
    {
    }

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

    // ── Prefund ──────────────────────────────────────────────────────────────

    public function adjustPrefund(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->money->adjustPrefund(
                $id,
                (string) $request->input('type'),
                (float) $request->input('amount'),
                (string) $request->input('description'),
                (string) $request->user()->id,
            );
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Prefund balance updated successfully.'] + $result);
    }

    // ── Auto replenish ───────────────────────────────────────────────────────

    public function showAutoReplenish(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->money->getAutoReplenishSettings($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function updateAutoReplenish(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->money->updateAutoReplenishSettings($id, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Auto replenish settings saved successfully.'] + $result);
    }

    // ── Agent commission settings ───────────────────────────────────────────

    public function showAgentCommission(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json([
                'settings' => $this->money->getAgentCommissionSettings($id),
                'emails' => $this->money->listAgentCommissionEmails($id),
                'commission_types' => MerchantMoneyService::COMMISSION_TYPES,
            ]);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function updateAgentCommission(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->money->updateAgentCommissionSettings($id, $request->all());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Agent commission settings saved successfully.', 'settings' => $result]);
    }

    public function addAgentCommissionEmail(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->money->addAgentCommissionEmail($id, (string) $request->input('email'));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'E-mail added successfully.', 'email' => $result], 201);
    }

    public function updateAgentCommissionEmail(Request $request, int $id, int $emailId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->money->updateAgentCommissionEmail($id, $emailId, $request->input('email'), $request->input('status'));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'E-mail updated successfully.', 'email' => $result]);
    }

    public function deleteAgentCommissionEmail(Request $request, int $id, int $emailId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $this->money->deleteAgentCommissionEmail($id, $emailId);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'E-mail removed successfully.']);
    }
}
