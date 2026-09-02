<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskCashMeterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskCashMeterController extends Controller
{
    private const MODULE_PATH = '/kiosk/cash-meters';

    public function __construct(private readonly KioskCashMeterService $cashMeters) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'branches' => $this->cashMeters->listBranches(),
            'terminals' => $this->cashMeters->listTerminals(),
            'transaction_types' => KioskCashMeterService::TRANSACTION_TYPES,
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json(['data' => $this->cashMeters->listTerminals($branchId)]);
    }

    public function meters(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $terminalId = (int) $request->query('terminal_id');
        $type = (string) $request->query('type');

        if (! $terminalId) {
            return response()->json(['message' => 'Please select a terminal.'], 422);
        }
        if (! array_key_exists($type, KioskCashMeterService::TRANSACTION_TYPES)) {
            return response()->json(['message' => 'Please select a transaction type.'], 422);
        }

        return response()->json($this->cashMeters->meters($terminalId, $type));
    }
}
