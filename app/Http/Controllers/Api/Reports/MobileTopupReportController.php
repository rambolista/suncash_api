<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Reports\MobileTopupReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileTopupReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = Report::MODULE_PATH;

    public function __construct(private readonly Report $report) {}

    public function options(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->report->options());
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $f = $this->filters($request);

        return response()->json(['columns' => Report::COLUMNS, 'data' => $this->report->list($f), 'summary' => $this->report->summary($f)]);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filters($request);

        return $this->exportTabularReport(
            $request,
            (string) $request->query('format', 'csv'),
            Report::COLUMNS,
            $this->report->list($f, null),
            'Mobile Top Up Report',
            'mobile-topup-report',
            array_filter($f, fn ($v) => filled($v)),
            maxPdfRows: 1000,
            summary: $this->report->summary($f, false),
        );
    }

    /** Dates are optional (legacy listed everything) but only apply as a pair, and End must not precede Start. */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account' => ['nullable', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:40'],
        ], [
            'from.required_with' => 'Please fill up date from and date to.',
            'to.required_with' => 'Please fill up date from and date to.',
            'to.after_or_equal' => 'End date should be greater than Start date.',
        ]);

        return [
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'account' => $v['account'] ?? null,
            'provider' => $v['provider'] ?? null,
        ];
    }
}
