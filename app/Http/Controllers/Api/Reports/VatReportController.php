<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\VatReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VatReportController extends Controller
{
    protected const MODULE_PATH = '/reports/vat';

    public function __construct(private readonly VatReportService $report) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->report->report($v['from'], $v['to']));
    }
}
