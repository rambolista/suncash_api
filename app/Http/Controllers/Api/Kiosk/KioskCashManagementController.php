<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Kiosk\KioskCashManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KioskCashManagementController extends Controller
{
    protected const MODULE_PATH = '/kiosk/cash-management';

    public function __construct(private readonly KioskCashManagementService $service)
    {
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

        return response()->json([
            'deposits' => $this->service->listDeposits(),
            'stores' => $this->service->listStores(),
            'terminals' => $this->service->listTerminals(),
        ]);
    }

    public function banks(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = (int) $request->query('branch_id');

        return response()->json(['banks' => $branchId ? $this->service->getBanksForBranch($branchId) : []]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            return response()->json(['data' => $this->service->viewDetails($id)]);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }
    }

    public function uploadReceipt(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'receipt' => ['required', 'image', 'max:5120'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $path = $request->file('receipt')->store('kiosk-cash-management-receipts', 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->service->confirmDeposit($id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Cash Management', 'confirmed', "Set deposit #{$id} to {$request->input('action')}.", null, $request);

        return response()->json(['msg' => $result['message'], 'success' => $result['success']]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        try {
            $result = $this->service->deleteDeposit($id, $request->all(), $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Kiosk Cash Management', 'deleted', "Deleted deposit #{$id}.", null, $request);

        return response()->json(['msg' => $result['message'], 'success' => $result['success']]);
    }
}
