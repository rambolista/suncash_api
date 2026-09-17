<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Kiosk\KioskConfirmCustomerServiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskConfirmCustomerServiceController extends Controller
{
    protected const MODULE_PATH = '/kiosk/confirm-customer-service';

    public function __construct(private readonly KioskConfirmCustomerServiceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $dateFrom = $request->query('date_from') ?: now()->toDateString();
        $dateTo = $request->query('date_to') ?: now()->toDateString();
        $terminalId = $request->query('terminal_id');
        $limit = max(1, min((int) ($request->query('limit') ?: 10), 500));

        return response()->json([
            'rows' => $this->service->list($dateFrom, $dateTo, $terminalId ? (int) $terminalId : null, $limit),
            'terminals' => $this->service->listTerminals(),
        ]);
    }

    public function sessionLogs(Request $request, string $sessionId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['rows' => $this->service->sessionLogs($sessionId)]);
    }
}
