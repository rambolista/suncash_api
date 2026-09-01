<?php

namespace App\Http\Controllers\Api\FloatManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\FloatManagement\StoreFloatAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CurrentStoreFloatController extends Controller
{
    private const MODULE_PATH = '/float-management/current-store-float-amounts';

    public function __construct(private readonly StoreFloatAccountService $storeFloat) {}

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
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->storeFloat->listCurrentAmounts(
            $request->query('search_type'),
            $request->query('search_value'),
        ));
    }

    public function createAccount(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $account = $this->storeFloat->createAccount(
                (int) $request->input('merchant_id'),
                (float) $request->input('minimum_account'),
                (float) $request->input('maximum_account'),
                (string) $request->input('email_address'),
                $this->actorName($request),
            );
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'request_account', "Requested store float account for merchant #{$account->merchant_id} (min {$account->minimum_account}, max {$account->maximum_account})", $account, $request);

        return response()->json(['message' => 'Store float account requested successfully.', 'account' => $account], 201);
    }

    public function topup(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $account = $this->storeFloat->topup($id, (float) $request->input('amount'), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'topup', "Topped up store float account #{$account->id} (merchant #{$account->merchant_id}) by {$request->input('amount')}", $account, $request);

        return response()->json(['message' => 'Store float account topped up successfully.', 'account' => $account]);
    }

    public function requestReplenishment(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $replenishment = $this->storeFloat->requestReplenishment($id, (float) $request->input('amount'), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'request_replenishment', "Requested store float replenishment of {$replenishment->amount} for merchant #{$replenishment->merchant_id}", $replenishment, $request);

        return response()->json(['message' => 'Replenishment requested successfully.', 'replenishment' => $replenishment], 201);
    }
}
