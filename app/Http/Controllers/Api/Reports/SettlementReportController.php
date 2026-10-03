<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\SettlementReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettlementReportController extends Controller
{
    protected const MODULE_PATH = '/reports/settlement';

    public function __construct(private readonly SettlementReportService $report) {}

    public function summary(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $v = $this->filters($request);

        return response()->json($this->report->summary($v['tab'], $v['date']));
    }

    public function details(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $v = $this->filters($request, ['client_record_id' => ['required', 'integer', 'min:1']]);

        return response()->json($this->report->details($v['tab'], $v['date'], $v['client_record_id']));
    }

    private function filters(Request $request, array $extra = []): array
    {
        return $request->validate([
            'tab' => ['required', Rule::in(SettlementReportService::TABS)],
            'date' => ['required', 'date_format:Y-m-d'],
        ] + $extra);
    }
}
