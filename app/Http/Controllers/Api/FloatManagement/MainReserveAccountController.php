<?php

namespace App\Http\Controllers\Api\FloatManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\FloatManagement\MainReserveAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MainReserveAccountController extends Controller
{
    private const MODULE_PATH = '/float-management/main-reserve-account';

    public function __construct(private readonly MainReserveAccountService $mainReserve) {}

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

        return response()->json($this->mainReserve->list());
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->approve($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'approve', "Approved main reserve account request #{$account->id}", $account, $request);

        return response()->json(['message' => 'Request approved successfully.', 'account' => $account]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->reject($id, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'reject', "Rejected main reserve account request #{$account->id}", $account, $request);

        return response()->json(['message' => 'Request rejected successfully.', 'account' => $account]);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->confirm($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'confirm', "Confirmed main reserve account replenishment #{$account->id} of {$account->repl_amount}", $account, $request);

        return response()->json(['message' => 'Replenishment confirmed successfully.', 'account' => $account]);
    }

    public function topup(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->topup((float) $request->input('amount'), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'topup', "Topped up main reserve account #{$account->id} by {$request->input('amount')}", $account, $request);

        return response()->json(['message' => 'Main reserve account topped up successfully.', 'account' => $account]);
    }

    public function requestReplenishment(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $account = $this->mainReserve->requestReplenishment((float) $request->input('amount'), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Float Management', 'request_replenishment', "Requested main reserve account replenishment #{$account->id} of {$request->input('amount')}", $account, $request);

        return response()->json(['message' => 'Replenishment requested successfully.', 'account' => $account], 201);
    }
}
