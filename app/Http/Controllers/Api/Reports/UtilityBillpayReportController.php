<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Reports\UtilityBillpayReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UtilityBillpayReportController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/reports/utility-billpay';

    public function __construct(private readonly UtilityBillpayReportService $report) {}

    public function billers(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->report->billerCodes()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        [$source, $billerCode, $from, $to] = $this->filters($request);

        $result = $this->report->paginatedList($source, $billerCode, $from, $to, (int) $request->query('page', 1));
        $result['summary'] = $this->report->totalSummary($source, $billerCode, $from, $to);

        return response()->json($result);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_export')) {
            return $response;
        }

        [$source, $billerCode, $from, $to] = $this->filters($request);

        $rows = $this->report->exportRows($source, $billerCode, $from, $to);
        $summary = $this->report->totalSummary($source, $billerCode, $from, $to);
        $format = (string) $request->query('format', 'csv');

        return $this->exportTabularReport(
            $request,
            $format,
            UtilityBillpayReportService::COLUMNS,
            $rows,
            'Utility Billpay Report',
            'utility-billpay',
            array_filter(['from' => $from, 'to' => $to, 'biller' => $billerCode, 'source' => $source]),
            maxPdfRows: 1000,
            summary: [
                'Total Transaction Count' => $summary['transaction_count'],
                'Total Principle Amount' => number_format($summary['transaction_amount'], 2),
            ],
        );
    }

    private function filters(Request $request): array
    {
        $today = now()->toDateString();

        return [
            $request->query('source') ?: 'ALL',
            $request->query('biller') ?: null,
            $request->query('from') ?: $today,
            $request->query('to') ?: $today,
        ];
    }
}
