<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Reports\GlobalSalesReportService as Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GlobalSalesReportController extends Controller
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

    /** Branches of the picked merchant (legacy `getBranchbyMerchant`). */
    public function branches(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $merchant = $request->validate(['merchant' => ['required', 'integer', 'min:1']])['merchant'];

        return response()->json($this->report->branches((int) $merchant));
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $report = $this->report->list($this->filters($request));

        return response()->json(['columns' => Report::COLUMNS] + $report);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        $f = $this->filters($request);
        $report = $this->report->list($f);

        return $this->exportTabularReport(
            $request,
            (string) $request->query('format', 'csv'),
            Report::COLUMNS,
            $report['data'],
            'Global Sales Report',
            'global-sales-report',
            array_filter($f, fn ($v) => filled($v)),
            maxPdfRows: 1000,
            summary: $report['summary'],
        );
    }

    /** Both dates are required, as in legacy, and End must not precede Start. */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'merchant' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'integer', 'min:1'],
        ], [
            'from.required' => 'Please fill up date from and date to.',
            'to.required' => 'Please fill up date from and date to.',
            'to.after_or_equal' => 'End date should be greater than Start date.',
        ]);

        return ['from' => $v['from'], 'to' => $v['to'], 'merchant' => $v['merchant'] ?? null, 'branch' => $v['branch'] ?? null];
    }
}
