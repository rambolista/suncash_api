<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerLoginLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLoginLogController extends Controller
{
    private const SUCCESS_MENU_PATH = '/customers/logs';

    private const FAILED_MENU_PATH = '/customers/failed-logs';

    public function __construct(private readonly CustomerLoginLogService $logs) {}

    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            $validated['from'] ?? now()->toDateString(),
            $validated['to'] ?? now()->toDateString(),
        ];
    }

    public function success(Request $request): JsonResponse
    {
        abort_unless($this->userHasPermission($request->user(), self::SUCCESS_MENU_PATH, 'can_view'), 403, 'Forbidden.');

        [$from, $to] = $this->dateRange($request);

        return response()->json(['data' => $this->logs->successLogs($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function failed(Request $request): JsonResponse
    {
        abort_unless($this->userHasPermission($request->user(), self::FAILED_MENU_PATH, 'can_view'), 403, 'Forbidden.');

        [$from, $to] = $this->dateRange($request);

        return response()->json(['data' => $this->logs->failedLogs($from, $to), 'from' => $from, 'to' => $to]);
    }
}
