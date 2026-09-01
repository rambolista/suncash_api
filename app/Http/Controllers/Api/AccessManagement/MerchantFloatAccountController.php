<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Merchant\MerchantFloatAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantFloatAccountController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantFloatAccountService $floatAccounts)
    {
    }

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasTabPermission($request->user(), self::MODULE_PATH, 'float-account', $action)
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

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json($this->floatAccounts->getState($id));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->floatAccounts->toggleEnabled($id, (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Float Accounts', $result['enabled'] ? 'enabled' : 'disabled', ($result['enabled'] ? 'Enabled' : 'Disabled')." store float for merchant #{$id}.", null, $request);

        return response()->json(['message' => $result['enabled'] ? 'Store float enabled.' : 'Store float disabled.'] + $result);
    }

    public function request(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->floatAccounts->requestFloatAccount($id, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Float Accounts', 'requested', "Requested store float account for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Store float account requested successfully.', 'request' => $result], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->floatAccounts->updateFloatAccount($id, $request->all(), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Merchant Float Accounts', 'updated', "Updated store float account for merchant #{$id}.", null, $request);

        return response()->json(['message' => 'Store float account updated successfully.', 'account' => $result]);
    }
}
