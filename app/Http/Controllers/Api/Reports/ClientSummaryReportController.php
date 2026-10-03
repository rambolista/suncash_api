<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ClientSummaryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientSummaryReportController extends Controller
{
    protected const MODULE_PATH = '/reports/client-summary';

    public function __construct(private readonly ClientSummaryReportService $report) {}

    public function clients(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->report->clients()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $v = $request->validate([
            'client_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:0,1'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->report->list(
            $v['client_id'] ?? null,
            isset($v['status']) ? (int) $v['status'] : null,
            $v['start_date'] ?? null,
            $v['end_date'] ?? null,
        )]);
    }
}
