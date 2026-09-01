<?php

namespace App\Http\Controllers\Api\FloatManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\CashierMainReserveAccount;
use App\Services\FloatManagement\MainReserveAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SetMainReserveAccountController extends Controller
{
    private const MODULE_PATH = '/float-management/set-main-reserve-account';

    public function __construct(private readonly MainReserveAccountService $mainReserve) {}

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
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function show(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->mainReserve->current());
    }

    public function setup(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->setup($request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordCreated($request->user(), 'Float Management', $account, ['minimum_account', 'maximum_account', 'repl_amount', 'email_address', 'status'], $request);

        return response()->json(['message' => 'Main reserve account requested successfully.', 'account' => $account], 201);
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $before = CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)->orderByDesc('id')->first()?->getAttributes() ?? [];

        try {
            $account = $this->mainReserve->update($request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordUpdated($request->user(), 'Float Management', $account, $before, ['minimum_account', 'maximum_account', 'email_address'], $request);

        return response()->json(['message' => 'Main reserve account updated successfully.', 'account' => $account]);
    }
}
